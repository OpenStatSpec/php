<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\FidelityDiagnostic;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Core\DiagnosticCode;
use PDO;
use PDOStatement;
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

/** Reconstructs a typed php-spss V3 Dataset from the SQLite wide-table profile. */
final readonly class SqliteWideTableExporter
{
    public function __construct(private PDO $pdo) {}

    /**
     * @return array{dataset: Dataset, caseCount: int, diagnostics: list<FidelityDiagnostic>}
     */
    public function export(string $datasetName, string $targetFormat = 'sav'): array
    {
        if (!in_array($targetFormat, ['sav', 'zsav'], true)) {
            throw new UnsupportedOperation(DiagnosticCode::UnsupportedSourceFormat, 'The SQLite profile can only construct SAV or ZSAV datasets.');
        }
        $datasetStatement = $this->statement('SELECT table_name FROM datasets WHERE dataset_name = ?');
        $datasetStatement->execute([$datasetName]);
        $dataset = $datasetStatement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($dataset) || !is_string($dataset['table_name'] ?? null)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The requested dataset is not present in the SQLite catalogue.');
        }

        $variables = $this->variables($datasetName);
        $columns = array_map(fn(array $variable): string => $this->quote($variable['column_name']), $variables);
        $caseStatement = $this->statement(
            'SELECT ' . implode(', ', $columns) . ' FROM ' . $this->quote($dataset['table_name']) . ' ORDER BY "__case_ordinal"',
        );
        $caseStatement->execute();

        $rows = [];
        while (($row = $caseStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = array_map(
                static fn(array $variable): int|float|string|null => $row[$variable['column_name']] ?? null,
                $variables,
            );
        }

        $metadata = new FileMetadata(
            label: $this->fileLabel($datasetName),
            weightVariableName: $this->weightVariableName($datasetName),
            documents: $this->documents($datasetName),
            attributes: $this->fileAttributes($datasetName),
            variableSets: $this->variableSets($datasetName),
            multipleResponseSets: $this->multipleResponseSets($datasetName),
        );

        $typedVariables = [];
        foreach ($variables as $variable) {
            $dictionary = $this->dictionary($datasetName, $variable['ordinal']);
            $display = $this->display($datasetName, $variable['ordinal']);
            $isString = $variable['storage_kind'] === 'string';
            $type = $isString ? VariableType::STRING : VariableType::NUMERIC;
            $width = $isString ? $variable['source_width'] : 0;
            // SPSS stores format widths in one byte, but long-string storage widths are
            // separate and may be up to 32767 bytes. Do not cap the storage width.
            $printFormatWidth = $isString ? min($variable['format_width'], 255) : $variable['format_width'];
            $writeFormatWidth = $isString ? min($variable['write_format_width'], 255) : $variable['write_format_width'];

            $typedVariables[] = new VariableMetadata(
                name: $variable['source_name'],
                type: $type,
                width: $width,
                printFormat: new VariableFormat($variable['format_family'], $printFormatWidth, $variable['format_decimals']),
                writeFormat: new VariableFormat($variable['write_format_family'], $writeFormatWidth, $variable['write_format_decimals']),
                label: $variable['label'],
                valueLabels: new ValueLabelSet($dictionary['labels'], [$variable['source_name']]),
                missingValues: $dictionary['missing'],
                measure: $display['measure'],
                alignment: $display['alignment'],
                columns: $display['columns'],
                role: $this->role($datasetName, $variable['ordinal']),
                attributes: $this->variableAttributes($datasetName, $variable['ordinal'], $variable['source_name']),
                dictionaryIndex: $variable['ordinal'],
            );
        }

        return [
            'dataset' => new Dataset(
                new VariableDictionary($typedVariables),
                $rows,
                $metadata,
                $this->technicalMetadata($datasetName, $targetFormat),
            ),
            'caseCount' => count($rows),
            'diagnostics' => [],
        ];
    }

    /**
     * @return list<array{ordinal: int, source_name: string, column_name: string, storage_kind: string, source_width: int, format_family: int, format_width: int, format_decimals: int, write_format_family: int, write_format_width: int, write_format_decimals: int, label: ?string}>
     */
    private function variables(string $datasetName): array
    {
        $statement = $this->statement(
            'SELECT ordinal, source_name, column_name, storage_kind, source_width, format_family, format_width, format_decimals, write_format_family, write_format_width, write_format_decimals, label FROM variables WHERE dataset_name = ? ORDER BY ordinal',
        );
        $statement->execute([$datasetName]);
        $variables = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($variables === []) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The requested dataset has no variable catalogue entries.');
        }

        return array_values(array_map(
            static function (array $variable): array {
                $ordinal = $variable['ordinal'] ?? null;
                $sourceName = $variable['source_name'] ?? null;
                $columnName = $variable['column_name'] ?? null;
                $storageKind = $variable['storage_kind'] ?? null;
                $sourceWidth = $variable['source_width'] ?? null;
                $formatFamily = $variable['format_family'] ?? null;
                $formatWidth = $variable['format_width'] ?? null;
                $formatDecimals = $variable['format_decimals'] ?? null;
                $writeFormatFamily = $variable['write_format_family'] ?? null;
                $writeFormatWidth = $variable['write_format_width'] ?? null;
                $writeFormatDecimals = $variable['write_format_decimals'] ?? null;
                $label = $variable['label'] ?? null;
                if (!is_int($ordinal) || !is_string($sourceName) || !is_string($columnName) || !is_string($storageKind) || !is_int($sourceWidth) || !is_int($formatFamily) || !is_int($formatWidth) || !is_int($formatDecimals) || !is_int($writeFormatFamily) || !is_int($writeFormatWidth) || !is_int($writeFormatDecimals)) {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The SQLite variable catalogue is malformed.');
                }

                return [
                    'ordinal' => $ordinal,
                    'source_name' => $sourceName,
                    'column_name' => $columnName,
                    'storage_kind' => $storageKind,
                    'source_width' => $sourceWidth,
                    'format_family' => $formatFamily,
                    'format_width' => $formatWidth,
                    'format_decimals' => $formatDecimals,
                    'write_format_family' => $writeFormatFamily,
                    'write_format_width' => $writeFormatWidth,
                    'write_format_decimals' => $writeFormatDecimals,
                    'label' => is_string($label) ? $label : null,
                ];
            },
            $variables,
        ));
    }

    /** @return array{labels: list<ValueLabel>, missing: MissingValues} */
    private function dictionary(string $datasetName, int $ordinal): array
    {
        $labels = $this->statement('SELECT value_kind, numeric_value, text_value, label FROM value_labels WHERE dataset_name = ? AND variable_ordinal = ? ORDER BY ordinal');
        $labels->execute([$datasetName, $ordinal]);
        $typedLabels = [];
        while (($row = $labels->fetch(PDO::FETCH_ASSOC)) !== false) {
            $value = $this->typedValue($row);
            if ($value !== null && is_string($row['label'] ?? null)) {
                $typedLabels[] = new ValueLabel($value, $row['label']);
            }
        }

        $rule = $this->statement('SELECT missing_format FROM missing_rules WHERE dataset_name = ? AND variable_ordinal = ?');
        $rule->execute([$datasetName, $ordinal]);
        $format = $rule->fetchColumn();
        if (!is_int($format) || $format === 0) {
            return ['labels' => $typedLabels, 'missing' => MissingValues::none()];
        }

        $valuesStatement = $this->statement('SELECT value_kind, numeric_value, text_value FROM missing_rule_values WHERE dataset_name = ? AND variable_ordinal = ? ORDER BY ordinal');
        $valuesStatement->execute([$datasetName, $ordinal]);
        $values = [];
        while (($row = $valuesStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $value = $this->typedValue($row);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        $missing = match ($format) {
            -2 => MissingValues::range($this->numeric($values[0] ?? null), $this->numeric($values[1] ?? null)),
            -3 => MissingValues::rangeAndValue($this->numeric($values[0] ?? null), $this->numeric($values[1] ?? null), $this->numeric($values[2] ?? null)),
            default => MissingValues::discrete(...$values),
        };

        return ['labels' => $typedLabels, 'missing' => $missing];
    }

    /** @return array{measure: Measure, columns: int, alignment: Alignment} */
    private function display(string $datasetName, int $ordinal): array
    {
        $statement = $this->statement('SELECT measurement_level, display_width, alignment FROM variable_display_metadata WHERE dataset_name = ? AND variable_ordinal = ?');
        $statement->execute([$datasetName, $ordinal]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['measure' => Measure::UNKNOWN, 'columns' => 8, 'alignment' => Alignment::LEFT];
        }

        return [
            'measure' => Measure::tryFrom(is_int($row['measurement_level'] ?? null) ? $row['measurement_level'] : 0) ?? Measure::UNKNOWN,
            'columns' => max(0, is_int($row['display_width'] ?? null) ? $row['display_width'] : 8),
            'alignment' => Alignment::tryFrom(is_int($row['alignment'] ?? null) ? $row['alignment'] : 0) ?? Alignment::LEFT,
        ];
    }

    private function fileLabel(string $datasetName): ?string
    {
        $statement = $this->statement('SELECT meta_value FROM dataset_metadata WHERE dataset_name = ? AND meta_key = ?');
        $statement->execute([$datasetName, 'file_label']);
        $label = $statement->fetchColumn();

        return is_string($label) ? $label : null;
    }

    private function weightVariableName(string $datasetName): ?string
    {
        $statement = $this->statement(
            'SELECT variable.source_name FROM dataset_weight_variables weight '
            . 'INNER JOIN variables variable ON variable.dataset_name = weight.dataset_name '
            . 'AND variable.ordinal = weight.variable_ordinal WHERE weight.dataset_name = ?',
        );
        $statement->execute([$datasetName]);
        $name = $statement->fetchColumn();
        if ($name === false) {
            return null;
        }
        if (!is_string($name) || $name === '') {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The SQLite weight-variable catalogue references an unknown source variable.');
        }

        return $name;
    }

    /** Rebuild the V3 fields whose file representation the writer supports. */
    private function technicalMetadata(string $datasetName, string $targetFormat): FileTechnicalMetadata
    {
        $statement = $this->statement(
            'SELECT source_version, provenance, encoding, product_name FROM file_technical_metadata WHERE dataset_name = ?',
        );
        $statement->execute([$datasetName]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $targetIsZsav = $targetFormat === 'zsav';

        return new FileTechnicalMetadata(
            sourceFormat: $targetFormat,
            recordType: $targetIsZsav ? '$FL3' : '$FL2',
            sourceVersion: is_array($row) && is_string($row['source_version'] ?? null) && $row['source_version'] !== '' ? $row['source_version'] : null,
            provenance: is_array($row) && is_string($row['provenance'] ?? null) && $row['provenance'] !== '' ? $row['provenance'] : null,
            encoding: is_array($row) && is_string($row['encoding'] ?? null) && $row['encoding'] !== '' ? $row['encoding'] : 'UTF-8',
            productName: is_array($row) && is_string($row['product_name'] ?? null) && $row['product_name'] !== '' ? $row['product_name'] : null,
            compression: $targetIsZsav ? 2 : 1,
        );
    }

    /** @return list<string> */
    private function documents(string $datasetName): array
    {
        $statement = $this->statement('SELECT text FROM documents WHERE dataset_name = ? ORDER BY ordinal');
        $statement->execute([$datasetName]);

        return array_values(array_filter($statement->fetchAll(PDO::FETCH_COLUMN), 'is_string'));
    }

    /** @param array<string, mixed> $row */
    private function typedValue(array $row): int|float|string|null
    {
        if (($row['value_kind'] ?? null) === 'text') {
            return is_string($row['text_value'] ?? null) ? $row['text_value'] : null;
        }

        $value = $row['numeric_value'] ?? null;

        return is_int($value) || is_float($value) ? $value : null;
    }

    private function numeric(mixed $value): int|float
    {
        if (!is_int($value) && !is_float($value)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A numeric user-missing rule contains a non-numeric catalogue value.');
        }

        return $value;
    }

    private function statement(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The SQLite profile could not prepare a required catalogue query.');
        }

        return $statement;
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
    private function role(string $datasetName, int $ordinal): VariableRole
    {
        $statement = $this->statement('SELECT role FROM variable_roles WHERE dataset_name = ? AND variable_ordinal = ?');
        $statement->execute([$datasetName, $ordinal]);
        $role = $statement->fetchColumn();
        if (!is_int($role) || ($typed = VariableRole::tryFrom($role)) === null) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The SQLite variable role catalogue is malformed.');
        }

        return $typed;
    }

    /** @return list<VariableAttribute> */
    private function variableAttributes(string $datasetName, int $ordinal, string $variableName): array
    {
        $statement = $this->statement('SELECT attribute_name, ordinal, value FROM variable_attributes WHERE dataset_name = ? AND variable_ordinal = ? ORDER BY attribute_name, ordinal');
        $statement->execute([$datasetName, $ordinal]);

        $attributes = [];
        foreach ($this->attributeValues($statement) as $name => $values) {
            $attributes[] = new VariableAttribute($variableName, $name, $values);
        }

        return $attributes;
    }

    /** @return list<FileAttribute> */
    private function fileAttributes(string $datasetName): array
    {
        $statement = $this->statement('SELECT attribute_name, ordinal, value FROM file_attributes WHERE dataset_name = ? ORDER BY attribute_name, ordinal');
        $statement->execute([$datasetName]);

        $attributes = [];
        foreach ($this->attributeValues($statement) as $name => $values) {
            $attributes[] = new FileAttribute($name, $values);
        }

        return $attributes;
    }

    /** @return array<string, list<string>> */
    private function attributeValues(PDOStatement $statement): array
    {
        /** @var array<string, list<string>> $grouped */
        $grouped = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $name = $row['attribute_name'] ?? null;
            $value = $row['value'] ?? null;
            if (!is_string($name) || $name === '' || !is_string($value)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The SQLite attribute catalogue is malformed.');
            }
            $grouped[$name][] = $value;
        }

        return $grouped;
    }

    /** @return list<VariableSet> */
    private function variableSets(string $datasetName): array
    {
        $sets = $this->statement('SELECT set_ordinal, name FROM variable_sets WHERE dataset_name = ? ORDER BY set_ordinal');
        $sets->execute([$datasetName]);
        $members = $this->statement('SELECT member.member_ordinal, variable.source_name FROM variable_set_members member LEFT JOIN variables variable ON variable.dataset_name = member.dataset_name AND variable.ordinal = member.variable_ordinal WHERE member.dataset_name = ? AND member.set_ordinal = ? ORDER BY member.member_ordinal');
        $result = [];
        while (($set = $sets->fetch(PDO::FETCH_ASSOC)) !== false) {
            $ordinal = $set['set_ordinal'] ?? null;
            $name = $set['name'] ?? null;
            if (!is_int($ordinal) || !is_string($name) || $name === '') {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The SQLite variable-set catalogue is malformed.');
            }
            $members->execute([$datasetName, $ordinal]);
            $names = [];
            while (($member = $members->fetch(PDO::FETCH_ASSOC)) !== false) {
                $sourceName = $member['source_name'] ?? null;
                if (!is_string($sourceName) || $sourceName === '') {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A SQLite variable set references an unknown variable.');
                }
                $names[] = $sourceName;
            }
            $result[] = new VariableSet($name, $names);
        }

        return $result;
    }

    /** @return list<MultipleResponseSet> */
    private function multipleResponseSets(string $datasetName): array
    {
        $sets = $this->statement('SELECT set_ordinal, name, set_type, label, counted_value_kind, counted_numeric_value, counted_text_value, category_labels, label_source FROM multiple_response_sets WHERE dataset_name = ? ORDER BY set_ordinal');
        $sets->execute([$datasetName]);
        $members = $this->statement('SELECT member.member_ordinal, variable.source_name FROM multiple_response_set_members member LEFT JOIN variables variable ON variable.dataset_name = member.dataset_name AND variable.ordinal = member.variable_ordinal WHERE member.dataset_name = ? AND member.set_ordinal = ? ORDER BY member.member_ordinal');
        $result = [];
        while (($set = $sets->fetch(PDO::FETCH_ASSOC)) !== false) {
            $ordinal = $set['set_ordinal'] ?? null;
            $name = $set['name'] ?? null;
            $type = is_string($set['set_type'] ?? null) ? MultipleResponseSetType::tryFrom($set['set_type']) : null;
            $categoryLabels = is_string($set['category_labels'] ?? null) ? MultipleResponseCategoryLabels::tryFrom($set['category_labels']) : null;
            $labelSource = is_string($set['label_source'] ?? null) ? MultipleResponseLabelSource::tryFrom($set['label_source']) : null;
            $label = $set['label'] ?? null;
            if (!is_int($ordinal) || !is_string($name) || $name === '' || $type === null || $categoryLabels === null || $labelSource === null || ($label !== null && !is_string($label))) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The SQLite multiple-response-set catalogue is malformed.');
            }
            $countedValue = $this->countedValue($set);
            $members->execute([$datasetName, $ordinal]);
            $names = [];
            while (($member = $members->fetch(PDO::FETCH_ASSOC)) !== false) {
                $sourceName = $member['source_name'] ?? null;
                if (!is_string($sourceName) || $sourceName === '') {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A SQLite multiple-response set references an unknown variable.');
                }
                $names[] = $sourceName;
            }
            try {
                $result[] = new MultipleResponseSet($name, $type, $names, $label, $countedValue, $categoryLabels, $labelSource);
            } catch (\InvalidArgumentException $exception) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The SQLite multiple-response-set catalogue is inconsistent: ' . $exception->getMessage());
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $set */
    private function countedValue(array $set): int|string|null
    {
        $kind = $set['counted_value_kind'] ?? null;
        if ($kind === null) {
            return null;
        }
        if ($kind === 'text' && is_string($set['counted_text_value'] ?? null)) {
            return $set['counted_text_value'];
        }
        $value = $set['counted_numeric_value'] ?? null;
        if ($kind === 'numeric' && (is_int($value) || is_float($value)) && floor((float) $value) === (float) $value) {
            return (int) $value;
        }

        throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A SQLite multiple-response set has an invalid counted value.');
    }


}
