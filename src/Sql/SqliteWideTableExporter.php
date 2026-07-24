<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\FidelityDiagnostic;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;
use PDOStatement;

/** Reconstructs a SAV writer payload from the SQLite wide-table profile. */
final readonly class SqliteWideTableExporter
{
    public function __construct(private PDO $pdo) {}

    /**
     * @return array{
     *     payload: array{header: array{}, variables: list<array{name: string, format: int, width: int, decimals: int, label: ?string, data: list<mixed>}>, documents: list<mixed>, info: array{}},
     *     caseCount: int,
     *     diagnostics: list<FidelityDiagnostic>
     * }
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
            $rows[] = $row;
        }

        $writerVariables = [];
        $diagnostics = [];
        if ($this->hasUnrestoredDatasetMetadata($datasetName)) {
            $diagnostics[] = new FidelityDiagnostic('dataset_metadata_not_preserved', 'File labels and document records are retained in the catalogue but are not yet reconstructed in the SAV writer payload.');
        }
        foreach ($variables as $variable) {
            $dictionary = $this->dictionary($datasetName, $variable['ordinal']);
            if ($dictionary['unsupported']) {
                $diagnostics[] = new FidelityDiagnostic('missing_rule_not_preserved', 'The external SAV writer cannot preserve a range-plus-discrete SPSS user-missing rule.');
            }
            $values = [];
            foreach ($rows as $row) {
                $values[] = $row[$variable['column_name']] ?? null;
            }

            $isString = $variable['storage_kind'] === 'string';
            $writerVariables[] = [
                'name' => $variable['source_name'],
                'format' => $variable['format_family'],
                'width' => $isString ? $variable['source_width'] : $variable['format_width'],
                'decimals' => $variable['format_decimals'],
                'label' => $variable['label'],
                'data' => $values,
                'values' => $dictionary['values'],
                'missing' => $dictionary['missing'],
            ];
        }

        return [
            'payload' => [
                'header' => [],
                'variables' => $writerVariables,
                'documents' => [],
                'info' => [],
            ],
            'caseCount' => count($rows),
            'diagnostics' => $diagnostics,
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

    private function hasUnrestoredDatasetMetadata(string $datasetName): bool
    {
        $statement = $this->statement('SELECT 1 FROM dataset_metadata WHERE dataset_name = ? UNION ALL SELECT 1 FROM documents WHERE dataset_name = ? LIMIT 1');
        $statement->execute([$datasetName, $datasetName]);

        return $statement->fetchColumn() !== false;
    }
    /** @return array{values: array<int|string, string>, missing: list<int|float|string>, unsupported: bool} */
    private function dictionary(string $datasetName, int $ordinal): array
    {
        $labels = $this->statement('SELECT value_kind, numeric_value, text_value, label FROM value_labels WHERE dataset_name = ? AND variable_ordinal = ? ORDER BY ordinal');
        $labels->execute([$datasetName, $ordinal]);
        $values = [];
        while (($row = $labels->fetch(PDO::FETCH_ASSOC)) !== false) {
            $key = $row['value_kind'] === 'text' ? $row['text_value'] : $row['numeric_value'];
            if ((is_string($key) || is_int($key) || is_float($key)) && is_string($row['label'] ?? null)) {
                $values[(string) $key] = $row['label'];
            }
        }

        $rule = $this->statement('SELECT missing_format FROM missing_rules WHERE dataset_name = ? AND variable_ordinal = ?');
        $rule->execute([$datasetName, $ordinal]);
        $format = $rule->fetchColumn();
        if (!is_int($format) || $format === -3) {
            return ['values' => $values, 'missing' => [], 'unsupported' => $format === -3];
        }
        $missingStatement = $this->statement('SELECT value_kind, numeric_value, text_value FROM missing_rule_values WHERE dataset_name = ? AND variable_ordinal = ? ORDER BY ordinal');
        $missingStatement->execute([$datasetName, $ordinal]);
        $missing = [];
        while (($row = $missingStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $value = $row['value_kind'] === 'text' ? $row['text_value'] : $row['numeric_value'];
            if (is_string($value) || is_int($value) || is_float($value)) {
                $missing[] = $value;
            }
        }

        return ['values' => $values, 'missing' => $missing, 'unsupported' => false];
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
