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
use SPSS\Sav\FileMetadata;
use SPSS\Sav\Measure;
use SPSS\Sav\MissingValues;
use SPSS\Sav\ValueLabel;
use SPSS\Sav\ValueLabelSet;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableFormat;
use SPSS\Sav\VariableMetadata;
use SPSS\Sav\VariableType;

/** Reconstructs a typed php-spss V3 Dataset from the SQLite wide-table profile. */
final readonly class SqliteWideTableExporter
{
    public function __construct(private PDO $pdo) {}

    /**
     * @return array{dataset: Dataset, caseCount: int, diagnostics: list<FidelityDiagnostic>}
     */
    public function export(string $datasetName): array
    {
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
            documents: $this->documents($datasetName),
        );

        $typedVariables = [];
        foreach ($variables as $variable) {
            $dictionary = $this->dictionary($datasetName, $variable['ordinal']);
            $display = $this->display($datasetName, $variable['ordinal']);
            $isString = $variable['storage_kind'] === 'string';
            $type = $isString ? VariableType::STRING : VariableType::NUMERIC;
            $width = $isString ? $variable['source_width'] : 0;
            $formatWidth = $isString ? min($width, 255) : $variable['format_width'];

            $typedVariables[] = new VariableMetadata(
                name: $variable['source_name'],
                type: $type,
                width: $width,
                printFormat: new VariableFormat($variable['format_family'], $formatWidth, $variable['format_decimals']),
                writeFormat: new VariableFormat($variable['format_family'], $formatWidth, $variable['format_decimals']),
                label: $variable['label'],
                valueLabels: new ValueLabelSet($dictionary['labels'], [$variable['source_name']]),
                missingValues: $dictionary['missing'],
                measure: $display['measure'],
                alignment: $display['alignment'],
                columns: $display['columns'],
                dictionaryIndex: $variable['ordinal'],
            );
        }

        return [
            'dataset' => new Dataset(new VariableDictionary($typedVariables), $rows, $metadata),
            'caseCount' => count($rows),
            'diagnostics' => [],
        ];
    }

    /**
     * @return list<array{ordinal: int, source_name: string, column_name: string, storage_kind: string, source_width: int, format_family: int, format_width: int, format_decimals: int, label: ?string}>
     */
    private function variables(string $datasetName): array
    {
        $statement = $this->statement(
            'SELECT ordinal, source_name, column_name, storage_kind, source_width, format_family, format_width, format_decimals, label FROM variables WHERE dataset_name = ? ORDER BY ordinal',
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
                $label = $variable['label'] ?? null;
                if (!is_int($ordinal) || !is_string($sourceName) || !is_string($columnName) || !is_string($storageKind) || !is_int($sourceWidth) || !is_int($formatFamily) || !is_int($formatWidth) || !is_int($formatDecimals)) {
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
}
