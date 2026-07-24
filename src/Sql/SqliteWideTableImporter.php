<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;
use Throwable;

/** SQLite profile for one SPSS dataset as one native wide SQL table. */
final readonly class SqliteWideTableImporter
{
    public function __construct(private PDO $pdo) {}

    /** @param array{variables: array<int, mixed>, data: array<int, mixed>} $source */
    public function import(array $source, string $datasetName): void
    {
        $variables = $this->variables($source['variables']);
        $tableName = 'dataset_' . $this->identifier($datasetName);

        $this->pdo->beginTransaction();
        try {
            $this->createCatalog();
            $this->createDataTable($tableName, $variables);
            $this->pdo->prepare('INSERT INTO datasets (dataset_name, table_name) VALUES (?, ?)')->execute([$datasetName, $tableName]);
            $catalog = $this->pdo->prepare('INSERT INTO variables (dataset_name, ordinal, source_name, column_name, storage_kind, source_width, format_family, format_width, format_decimals, label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($variables as $variable) {
                $catalog->execute([$datasetName, $variable['ordinal'], $variable['source'], $variable['column'], $variable['kind'], $variable['width'], $variable['formatFamily'], $variable['formatWidth'], $variable['formatDecimals'], $variable['label']]);
            }
            $this->insertCases($tableName, $variables, $source['data']);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function createCatalog(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS datasets (dataset_name TEXT NOT NULL PRIMARY KEY, table_name TEXT NOT NULL UNIQUE)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS variables (dataset_name TEXT NOT NULL, ordinal INTEGER NOT NULL, source_name TEXT NOT NULL, column_name TEXT NOT NULL, storage_kind TEXT NOT NULL, source_width INTEGER NOT NULL, format_family INTEGER NOT NULL, format_width INTEGER NOT NULL, format_decimals INTEGER NOT NULL, label TEXT NULL, PRIMARY KEY (dataset_name, ordinal), UNIQUE (dataset_name, column_name))');
    }

    /** @param list<array{ordinal: int, source: string, column: string, kind: string, width: int, formatFamily: int, formatWidth: int, formatDecimals: int, label: ?string}> $variables */
    private function createDataTable(string $tableName, array $variables): void
    {
        $columns = ['"__case_ordinal" INTEGER NOT NULL PRIMARY KEY'];
        foreach ($variables as $variable) {
            $columns[] = $this->quote($variable['column']) . ($variable['kind'] === 'string' ? ' TEXT NOT NULL' : ' REAL NULL');
        }
        $this->pdo->exec('CREATE TABLE ' . $this->quote($tableName) . ' (' . implode(', ', $columns) . ')');
    }

    /** @param list<array{ordinal: int, source: string, column: string, kind: string, width: int, formatFamily: int, formatWidth: int, formatDecimals: int, label: ?string}> $variables
     * @param array<int, mixed> $rows
     */
    private function insertCases(string $tableName, array $variables, array $rows): void
    {
        $columns = array_merge(['__case_ordinal'], array_column($variables, 'column'));
        $quoted = array_map(fn(string $column): string => $this->quote($column), $columns);
        $params = array_map(static fn(string $column): string => ':' . $column, $columns);
        $statement = $this->pdo->prepare('INSERT INTO ' . $this->quote($tableName) . ' (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', $params) . ')');
        foreach ($rows as $caseOrdinal => $row) {
            $values = ['__case_ordinal' => $caseOrdinal + 1];
            foreach ($variables as $position => $variable) {
                $value = is_array($row) ? ($row[$variable['source']] ?? $row[$position] ?? null) : null;
                if ($value === null && $variable['kind'] === 'string') {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SPSS string values must not be represented as NULL.');
                }
                $values[$variable['column']] = $value;
            }
            $statement->execute($values);
        }
    }

    /** @param array<int, mixed> $sourceVariables
     * @return list<array{ordinal: int, source: string, column: string, kind: string, width: int, formatFamily: int, formatWidth: int, formatDecimals: int, label: ?string}>
     */
    private function variables(array $sourceVariables): array
    {
        if ($sourceVariables === []) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The source dataset has no variables.');
        }
        $used = ['__case_ordinal' => true];
        $variables = [];
        foreach ($sourceVariables as $index => $variable) {
            $source = is_array($variable) ? ($variable['name'] ?? null) : null;
            if (!is_string($source) || $source === '') {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every source variable must have a non-empty name.');
            }
            $base = $this->identifier($source);
            $column = $base;
            for ($suffix = 2; isset($used[$column]); ++$suffix) {
                $column = $base . '_' . $suffix;
            }
            $used[$column] = true;
            $type = $variable['type'] ?? '';
            $label = $variable['label'] ?? null;
            $variables[] = [
                'ordinal' => $index + 1,
                'source' => $source,
                'column' => $column,
                'kind' => is_string($type) && str_contains(strtolower($type), 'string') ? 'string' : 'numeric',
                'width' => is_int($variable['width'] ?? null) ? $variable['width'] : 0,
                'formatFamily' => is_int($variable['formatFamily'] ?? null) ? $variable['formatFamily'] : 5,
                'formatWidth' => is_int($variable['formatWidth'] ?? null) ? $variable['formatWidth'] : 8,
                'formatDecimals' => is_int($variable['formatDecimals'] ?? null) ? $variable['formatDecimals'] : 0,
                'label' => is_string($label) ? $label : null,
            ];
        }
        return $variables;
    }

    private function identifier(string $value): string
    {
        $identifier = trim(strtolower((string) preg_replace('/[^a-zA-Z0-9_]+/', '_', $value)), '_');
        return $identifier === '' ? 'data' : $identifier;
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
