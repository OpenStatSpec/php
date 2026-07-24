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
        foreach ($variables as $variable) {
            $values = [];
            foreach ($rows as $row) {
                $values[] = $row[$variable['column_name']] ?? null;
            }

            $isString = $variable['storage_kind'] === 'string';
            $writerVariables[] = [
                'name' => $variable['source_name'],
                'format' => $isString ? 1 : 5,
                'width' => $isString ? $this->stringWidth($values) : 8,
                'decimals' => 0,
                'label' => $variable['label'],
                'data' => $values,
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
            'diagnostics' => [
                new FidelityDiagnostic(
                    'metadata_not_preserved',
                    'This SQLite profile currently exports variable names, labels, values, and case order only. Original SPSS formats, declared string widths, value labels, user-missing rules, documents, attributes, sets, display settings, and file metadata are not preserved.',
                ),
            ],
        ];
    }

    /**
     * @param list<mixed> $values
     */
    private function stringWidth(array $values): int
    {
        $width = 1;
        foreach ($values as $value) {
            if (is_string($value)) {
                $width = max($width, strlen($value));
            }
        }

        return $width;
    }

    /**
     * @return list<array{source_name: string, column_name: string, storage_kind: string, label: ?string}>
     */
    private function variables(string $datasetName): array
    {
        $statement = $this->statement(
            'SELECT source_name, column_name, storage_kind, label FROM variables WHERE dataset_name = ? ORDER BY ordinal',
        );
        $statement->execute([$datasetName]);
        $variables = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($variables === []) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The requested dataset has no variable catalogue entries.');
        }

        return array_values(array_map(
            static function (array $variable): array {
                $sourceName = $variable['source_name'] ?? null;
                $columnName = $variable['column_name'] ?? null;
                $storageKind = $variable['storage_kind'] ?? null;
                $label = $variable['label'] ?? null;
                if (!is_string($sourceName) || !is_string($columnName) || !is_string($storageKind)) {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The SQLite variable catalogue is malformed.');
                }

                return [
                    'source_name' => $sourceName,
                    'column_name' => $columnName,
                    'storage_kind' => $storageKind,
                    'label' => is_string($label) ? $label : null,
                ];
            },
            $variables,
        ));
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
