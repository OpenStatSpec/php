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
     *     payload: array{header: null, variables: list<array{name: string, type: string, label: ?string}>, valueLabels: list<mixed>, documents: list<mixed>, info: array{}, data: list<array<string, mixed>>},
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

        $data = [];
        while (($row = $caseStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $case = [];
            foreach ($variables as $variable) {
                $case[$variable['source_name']] = $row[$variable['column_name']] ?? null;
            }
            $data[] = $case;
        }

        return [
            'payload' => [
                'header' => null,
                'variables' => array_map(
                    static fn(array $variable): array => [
                        'name' => $variable['source_name'],
                        'type' => $variable['storage_kind'],
                        'label' => $variable['label'],
                    ],
                    $variables,
                ),
                'valueLabels' => [],
                'documents' => [],
                'info' => [],
                'data' => $data,
            ],
            'caseCount' => count($data),
            'diagnostics' => [
                new FidelityDiagnostic(
                    'metadata_not_preserved',
                    'This SQLite profile currently exports variable names, storage kinds, labels, values, and case order only. Value labels, user-missing rules, documents, attributes, sets, display settings, and file metadata are not preserved.',
                ),
            ],
        ];
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
