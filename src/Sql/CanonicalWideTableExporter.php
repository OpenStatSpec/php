<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\FidelityDiagnostic;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\SpssMissingValueSentinel;
use PDO;
use SPSS\Sav\Alignment;
use SPSS\Sav\Dataset;
use SPSS\Sav\FileAttribute;
use SPSS\Sav\FileMetadata;
use SPSS\Sav\FileTechnicalMetadata;
use SPSS\Sav\Measure;
use SPSS\Sav\MissingValues;
use SPSS\Sav\MultipleResponseCategoryLabels;
use SPSS\Sav\MultipleResponseLabelSource;
use SPSS\Sav\MultipleResponseSet;
use SPSS\Sav\MultipleResponseSetType;
use SPSS\Sav\ValueLabel;
use SPSS\Sav\ValueLabelSet;
use SPSS\Sav\VariableAttribute;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableFormat;
use SPSS\Sav\VariableMetadata;
use SPSS\Sav\VariableRole;
use SPSS\Sav\VariableSet;
use SPSS\Sav\VariableType;

/** Reads authoritative metadata and the existing wide table without projecting SQL state. */
final readonly class CanonicalWideTableExporter
{
    public function __construct(private PDO $pdo) {}

    /** @return array{dataset: Dataset, caseCount: int, diagnostics: list<FidelityDiagnostic>} */
    public function export(string $datasetName, string $targetFormat = 'sav'): array
    {
        if (!in_array($targetFormat, ['sav', 'zsav'], true)) {
            throw new UnsupportedOperation(DiagnosticCode::UnsupportedSourceFormat, 'Only SAV and ZSAV exports are supported.');
        }
        $dataset = $this->all('SELECT * FROM dataset WHERE dataset_name = ?', [$datasetName])[0] ?? null;
        if ($dataset === null) {
            throw $this->invalid('The requested canonical dataset is not present.');
        }
        $id = $this->string($dataset['dataset_id']);
        $variables = $this->all('SELECT * FROM variable WHERE dataset_id = ? ORDER BY source_ordinal', [$id]);
        if ($variables === []) {
            throw $this->invalid('The canonical dataset has no variables.');
        }
        $labelsByVariable = $this->all('SELECT link.variable_id, label.* FROM variable_value_label_set link JOIN variable ON variable.variable_id = link.variable_id JOIN value_label label ON label.value_label_set_id = link.value_label_set_id WHERE variable.dataset_id = ? ORDER BY link.variable_id, label.ordinal', [$id], 'variable_id');
        $attributesByVariable = $this->all('SELECT attr.* FROM variable_attribute attr JOIN variable ON variable.variable_id = attr.variable_id WHERE variable.dataset_id = ? ORDER BY attr.variable_id, attr.attribute_name, attr.array_ordinal', [$id], 'variable_id');
        $missingByVariable = $this->all('SELECT rule.* FROM missing_rule rule JOIN variable ON variable.variable_id = rule.variable_id WHERE variable.dataset_id = ? ORDER BY rule.variable_id, rule.ordinal', [$id], 'variable_id');
        $typedVariables = [];
        $columns = [];
        foreach ($variables as $variable) {
            $name = $this->string($variable['source_name']);
            $variableId = $this->string($variable['variable_id']);
            $columns[] = $this->quote($this->string($variable['physical_name']));
            $type = match ($variable['storage_kind']) {
                'string' => VariableType::STRING,
                'numeric' => VariableType::NUMERIC,
                default => throw $this->invalid('Invalid variable storage kind.'),
            };
            $labels = [];
            foreach ($labelsByVariable[$variableId] ?? [] as $label) {
                $labels[] = new ValueLabel($this->value($label['code_kind'], $label['numeric_code'], $label['string_code']), $this->text($label['label']));
            }
            $attributes = [];
            foreach ($this->attributes($attributesByVariable[$variableId] ?? []) as $attributeName => $values) {
                $attributes[] = new VariableAttribute($name, $attributeName, $values);
            }
            $typedVariables[] = new VariableMetadata(
                name: $name,
                type: $type,
                width: $type === VariableType::STRING ? $this->integer($variable['declared_string_width']) : 0,
                printFormat: $this->format($variable, 'print', $type),
                writeFormat: $this->format($variable, 'write', $type),
                label: $variable['variable_label'] === null ? null : $this->text($variable['variable_label']),
                valueLabels: new ValueLabelSet($labels, [$name]),
                missingValues: $this->missingValues($missingByVariable[$variableId] ?? []),
                measure: match ($variable['measurement_level']) {
                    'nominal' => Measure::NOMINAL,
                    'ordinal' => Measure::ORDINAL,
                    'scale' => Measure::SCALE,
                    default => Measure::tryFrom($this->integer($variable['measurement_level'] ?? 0)) ?? Measure::UNKNOWN,
                },
                alignment: Alignment::tryFrom($this->integer($variable['display_alignment'] ?? 0)) ?? Alignment::LEFT,
                columns: max(0, $this->integer($variable['display_width'] ?? 8)),
                role: VariableRole::tryFrom($this->integer($variable['variable_role'] ?? 0)) ?? throw $this->invalid('Invalid variable role.'),
                attributes: $attributes,
                dictionaryIndex: $this->integer($variable['source_ordinal']),
            );
        }
        $table = $this->quote($this->string($dataset['physical_table_name']));
        if ($dataset['physical_table_schema'] !== null && $dataset['physical_table_schema'] !== '') {
            $table = $this->quote($this->string($dataset['physical_table_schema'])) . '.' . $table;
        }
        $cases = $this->pdo->prepare('SELECT ' . implode(', ', $columns) . ' FROM ' . $table . ' ORDER BY ' . $this->quote('__case_ordinal'));
        if ($cases === false || !$cases->execute()) {
            throw $this->invalid('Could not read the physical wide table.');
        }
        $rows = [];
        while (($row = $cases->fetch(PDO::FETCH_ASSOC)) !== false) {
            $values = [];
            foreach ($variables as $variable) {
                $value = $row[$this->string($variable['physical_name'])];
                $values[] = $variable['storage_kind'] === 'string'
                    ? $this->text($value)
                    : ($value === null ? null : $this->numeric($value));
            }
            $rows[] = $values;
        }
        $attributes = [];
        foreach ($this->attributes($this->all('SELECT attribute_name, attribute_value FROM dataset_attribute WHERE dataset_id = ? ORDER BY attribute_name, array_ordinal', [$id])) as $name => $values) {
            $attributes[] = new FileAttribute($name, $values);
        }
        $weight = $this->all('SELECT variable.source_name FROM dataset_weight_variable weight LEFT JOIN variable ON variable.variable_id = weight.variable_id AND variable.dataset_id = weight.dataset_id WHERE weight.dataset_id = ?', [$id]);
        $documents = [];
        foreach ($this->all('SELECT document_text FROM document WHERE dataset_id = ? ORDER BY source_ordinal', [$id]) as $document) {
            $documents[] = $this->text($document['document_text']);
        }
        // These writer-supported provenance fields have no normative counterpart.
        $technical = $this->all('SELECT source_version, provenance, product_name FROM file_technical_metadata WHERE dataset_name = ?', [$datasetName])[0] ?? [];
        $zsav = $targetFormat === 'zsav';
        return [
            'dataset' => new Dataset(
                new VariableDictionary($typedVariables),
                $rows,
                new FileMetadata(
                    label: $dataset['dataset_label'] === null ? null : $this->text($dataset['dataset_label']),
                    weightVariableName: $weight === [] ? null : $this->string($weight[0]['source_name']),
                    documents: $documents,
                    attributes: $attributes,
                    variableSets: $this->variableSets($id),
                    multipleResponseSets: $this->multipleResponseSets($id),
                ),
                new FileTechnicalMetadata(
                    sourceFormat: $targetFormat,
                    recordType: $zsav ? '$FL3' : '$FL2',
                    sourceVersion: $this->optionalText($technical['source_version'] ?? null),
                    provenance: $this->optionalText($technical['provenance'] ?? null),
                    encoding: $this->optionalText($dataset['source_encoding']) ?? 'UTF-8',
                    productName: $this->optionalText($technical['product_name'] ?? null),
                    compression: $zsav ? 2 : 1,
                ),
            ),
            'caseCount' => count($rows),
            'diagnostics' => [],
        ];
    }

    /** @param array<string, mixed> $variable */
    private function format(array $variable, string $prefix, VariableType $type): VariableFormat
    {
        if ($type === VariableType::NUMERIC
            && ($variable[$prefix . '_format_family'] ?? null) === null
            && ($variable[$prefix . '_format_width'] ?? null) === null
            && ($variable[$prefix . '_format_decimals'] ?? null) === null
        ) {
            return new VariableFormat(5, 8, 2);
        }
        $width = $this->integer($variable[$prefix . '_format_width']);
        return new VariableFormat(
            $variable[$prefix . '_format_family'] === 'F' ? 5 : $this->integer($variable[$prefix . '_format_family']),
            $type === VariableType::STRING ? min($width, 255) : $width,
            $this->integer($variable[$prefix . '_format_decimals']),
        );
    }

    /** @param list<array<string, mixed>> $rules */
    private function missingValues(array $rules): MissingValues
    {
        if ($rules === []) {
            return MissingValues::none();
        }
        $range = $rules[0]['rule_kind'] === 'numeric_range' ? array_shift($rules) : null;
        $values = [];
        foreach ($rules as $rule) {
            if ($rule['rule_kind'] !== 'discrete') {
                throw $this->invalid('Invalid discrete missing rule.');
            }
            $values[] = $this->value($rule['code_kind'], $rule['numeric_value'], $rule['string_value']);
        }
        if ($range !== null) {
            $lower = $range['lower_special'] === 'LOWEST' ? SpssMissingValueSentinel::lowest() : $this->numeric($range['numeric_lower']);
            $upper = $range['upper_special'] === 'HIGHEST' ? SpssMissingValueSentinel::highest() : $this->numeric($range['numeric_upper']);
            return match (count($values)) {
                0 => MissingValues::range($lower, $upper),
                1 => MissingValues::rangeAndValue($lower, $upper, $this->numeric($values[0])),
                default => throw $this->invalid('A missing range permits at most one discrete value.'),
            };
        }
        return MissingValues::discrete(...$values);
    }

    /** @return list<VariableSet> */
    private function variableSets(string $datasetId): array
    {
        $sets = [];
        $membersBySet = $this->all('SELECT member.variable_set_id, variable.source_name FROM variable_set_member member JOIN variable_set owner ON owner.variable_set_id = member.variable_set_id LEFT JOIN variable ON variable.variable_id = member.variable_id AND variable.dataset_id = owner.dataset_id WHERE owner.dataset_id = ? ORDER BY member.variable_set_id, member.source_ordinal', [$datasetId], 'variable_set_id');
        foreach ($this->all('SELECT variable_set_id, set_name FROM variable_set WHERE dataset_id = ? ORDER BY source_ordinal', [$datasetId]) as $set) {
            $members = $membersBySet[$set['variable_set_id']] ?? [];
            $sets[] = new VariableSet($this->string($set['set_name']), array_map(fn(array $member): string => $this->string($member['source_name']), $members));
        }
        return $sets;
    }

    /** @return list<MultipleResponseSet> */
    private function multipleResponseSets(string $datasetId): array
    {
        $sets = [];
        $membersBySet = $this->all('SELECT member.multiple_response_set_id, variable.source_name FROM multiple_response_member member JOIN multiple_response_set owner ON owner.multiple_response_set_id = member.multiple_response_set_id LEFT JOIN variable ON variable.variable_id = member.variable_id AND variable.dataset_id = owner.dataset_id WHERE owner.dataset_id = ? ORDER BY member.multiple_response_set_id, member.source_ordinal', [$datasetId], 'multiple_response_set_id');
        foreach ($this->all('SELECT * FROM multiple_response_set WHERE dataset_id = ? ORDER BY source_ordinal', [$datasetId]) as $set) {
            $members = $membersBySet[$set['multiple_response_set_id']] ?? [];
            $counted = $set['counted_value_kind'] === null ? null : $this->value($set['counted_value_kind'], $set['counted_numeric_value'], $set['counted_string_value']);
            if (is_float($counted)) {
                if (floor($counted) !== $counted || $counted >= (float) PHP_INT_MAX || $counted < PHP_INT_MIN) {
                    throw $this->invalid('A multiple-response counted value must be an integer.');
                }
                $counted = (int) $counted;
            }
            try {
                $sets[] = new MultipleResponseSet(
                    $this->string($set['set_name']),
                    match ($set['set_kind']) {
                        'MD' => MultipleResponseSetType::DICHOTOMY,
                        'MC' => MultipleResponseSetType::CATEGORY,
                        default => throw $this->invalid('Invalid multiple-response set kind.'),
                    },
                    array_map(fn(array $member): string => $this->string($member['source_name']), $members),
                    $set['set_label'] === null ? null : $this->text($set['set_label']),
                    $counted,
                    MultipleResponseCategoryLabels::from($this->string($set['category_label_behavior'])),
                    MultipleResponseLabelSource::from($this->string($set['label_source'])),
                );
            } catch (\InvalidArgumentException|\ValueError $exception) {
                throw $this->invalid('Invalid multiple-response set: ' . $exception->getMessage());
            }
        }
        return $sets;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<string>>
     */
    private function attributes(array $rows): array
    {
        $attributes = [];
        foreach ($rows as $row) {
            $attributes[$this->string($row['attribute_name'])][] = $this->text($row['attribute_value']);
        }
        return $attributes;
    }

    private function value(mixed $kind, mixed $numeric, mixed $string): float|string
    {
        return match ($kind) {
            'numeric' => $this->numeric($numeric),
            'string' => $this->text($string),
            default => throw $this->invalid('Invalid dictionary value kind.'),
        };
    }

    private function numeric(mixed $value): float
    {
        if ((!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) || !is_finite((float) $value)) {
            throw $this->invalid('Invalid numeric value.');
        }
        return (float) $value;
    }

    private function integer(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1)) {
            throw $this->invalid('Invalid integer metadata.');
        }
        return (int) $value;
    }

    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw $this->invalid('Invalid string value.');
        }
        return $value;
    }

    private function string(mixed $value): string
    {
        $value = $this->text($value);
        if ($value === '') {
            throw $this->invalid('Required canonical name or identifier is empty.');
        }
        return $value;
    }

    private function optionalText(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : $this->text($value);
    }

    /**
     * @param list<mixed> $parameters
     * @return ($groupBy is null ? list<array<string, mixed>> : array<array-key, list<array<string, mixed>>>)
     */
    private function all(string $sql, array $parameters, ?string $groupBy = null): array
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false || !$statement->execute($parameters)) {
            throw $this->invalid('Could not read the canonical dataset.');
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($groupBy === null) {
            return array_values($rows);
        }
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row[$groupBy]][] = $row;
        }
        return $grouped;
    }

    private function quote(string $identifier): string
    {
        $quote = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`' : '"';
        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }

    private function invalid(string $message): UnsupportedOperation
    {
        return new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, $message);
    }
}
