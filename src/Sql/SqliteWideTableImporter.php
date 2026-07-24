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
    private SqliteProfile $profile;

    public function __construct(private PDO $pdo)
    {
        $this->profile = new SqliteProfile();
    }

    /** @param array<string, mixed> $source */
    public function import(array $source, string $datasetName): void
    {
        $variables = $this->variables($source['variables']);
        $this->profile->assertCanRepresent(count($variables));
        $tableName = 'dataset_' . $this->identifier($datasetName);

        $this->pdo->beginTransaction();
        try {
            $this->createCatalog();
            $this->storeDatasetMetadata($datasetName, $source);
            $this->storeDictionaryMetadata($datasetName, $source['variables'], $source['valueLabels'] ?? []);
            $this->storeDisplayMetadata($datasetName, is_array($source['displayParameters'] ?? null) ? $source['displayParameters'] : []);
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
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS dataset_metadata (dataset_name TEXT NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL, PRIMARY KEY (dataset_name, meta_key))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS documents (dataset_name TEXT NOT NULL, ordinal INTEGER NOT NULL, text TEXT NOT NULL, PRIMARY KEY (dataset_name, ordinal))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS value_labels (dataset_name TEXT NOT NULL, variable_ordinal INTEGER NOT NULL, ordinal INTEGER NOT NULL, value_kind TEXT NOT NULL, numeric_value REAL NULL, text_value TEXT NULL, label TEXT NOT NULL, PRIMARY KEY (dataset_name, variable_ordinal, ordinal))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS missing_rules (dataset_name TEXT NOT NULL, variable_ordinal INTEGER NOT NULL, missing_format INTEGER NOT NULL, PRIMARY KEY (dataset_name, variable_ordinal))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS missing_rule_values (dataset_name TEXT NOT NULL, variable_ordinal INTEGER NOT NULL, ordinal INTEGER NOT NULL, value_kind TEXT NOT NULL, numeric_value REAL NULL, text_value TEXT NULL, PRIMARY KEY (dataset_name, variable_ordinal, ordinal))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS variable_display_metadata (dataset_name TEXT NOT NULL, variable_ordinal INTEGER NOT NULL, measurement_level INTEGER NOT NULL, display_width INTEGER NOT NULL, alignment INTEGER NOT NULL, PRIMARY KEY (dataset_name, variable_ordinal))');
    }

    /** @param array{fileLabel?: ?string, documents?: list<string>} $source */
    private function storeDatasetMetadata(string $datasetName, array $source): void
    {
        if (is_string($source['fileLabel'] ?? null)) {
            $this->pdo->prepare('INSERT INTO dataset_metadata (dataset_name, meta_key, meta_value) VALUES (?, ?, ?)')->execute([$datasetName, 'file_label', $source['fileLabel']]);
        }
        $document = $this->pdo->prepare('INSERT INTO documents (dataset_name, ordinal, text) VALUES (?, ?, ?)');
        foreach ($source['documents'] ?? [] as $ordinal => $text) {
            $document->execute([$datasetName, $ordinal + 1, $text]);
        }
    }

    /**
     * @param array<int, mixed> $sourceVariables
     * @param array<int, mixed> $valueLabels
     */
    /** @param array<int, mixed> $displayParameters */
    private function storeDisplayMetadata(string $datasetName, array $displayParameters): void
    {
        $statement = $this->pdo->prepare('INSERT INTO variable_display_metadata (dataset_name, variable_ordinal, measurement_level, display_width, alignment) VALUES (?, ?, ?, ?, ?)');
        foreach ($displayParameters as $ordinal => $display) {
            if (!is_array($display) || !is_int($display['measure'] ?? null) || !is_int($display['columns'] ?? null) || !is_int($display['alignment'] ?? null)) {
                continue;
            }
            $statement->execute([$datasetName, $ordinal + 1, $display['measure'], $display['columns'], $display['alignment']]);
        }
    }
    /**
     * @param array<int, mixed> $sourceVariables
     * @param array<int, mixed> $valueLabels
     */
    private function storeDictionaryMetadata(string $datasetName, array $sourceVariables, array $valueLabels): void
    {
        $missing = $this->pdo->prepare('INSERT INTO missing_rules (dataset_name, variable_ordinal, missing_format) VALUES (?, ?, ?)');
        $missingValue = $this->pdo->prepare('INSERT INTO missing_rule_values (dataset_name, variable_ordinal, ordinal, value_kind, numeric_value, text_value) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($sourceVariables as $index => $variable) {
            $format = $this->field($variable, 'missingFormat');
            $values = $this->field($variable, 'missingValues');
            if (!is_int($format) || $format === 0 || !is_array($values)) {
                continue;
            }
            $missing->execute([$datasetName, $index + 1, $format]);
            foreach ($values as $ordinal => $value) {
                $missingValue->execute([$datasetName, $index + 1, $ordinal + 1, is_string($value) ? 'text' : 'numeric', is_numeric($value) ? (float) $value : null, is_string($value) ? $value : null]);
            }
        }
        $label = $this->pdo->prepare('INSERT INTO value_labels (dataset_name, variable_ordinal, ordinal, value_kind, numeric_value, text_value, label) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($valueLabels as $record) {
            $indexes = $this->field($record, 'indexes');
            $labels = $this->field($record, 'labels');
            if (!is_array($indexes) || !is_array($labels)) {
                continue;
            }
            foreach ($indexes as $index) {
                if (!is_int($index)) {
                    continue;
                }
                foreach ($labels as $ordinal => $item) {
                    $value = $this->field($item, 'value');
                    $text = $this->field($item, 'label');
                    if (!is_string($text)) {
                        continue;
                    }
                    $label->execute([$datasetName, $index + 1, $ordinal + 1, is_string($value) ? 'text' : 'numeric', is_numeric($value) ? (float) $value : null, is_string($value) ? $value : null, $text]);
                }
            }
        }
    }

    private function field(mixed $source, string $name): mixed
    {
        if (is_array($source)) {
            return $source[$name] ?? null;
        }
        if (is_object($source) && isset($source->{$name})) {
            return $source->{$name};
        }

        return null;
    }
    /** @param list<array{ordinal: int, source: string, column: string, kind: string, width: int, formatFamily: int, formatWidth: int, formatDecimals: int, label: ?string}> $variables */
    private function createDataTable(string $tableName, array $variables): void
    {
        $columns = [$this->profile->quoteIdentifier('__case_ordinal') . ' INTEGER NOT NULL PRIMARY KEY'];
        foreach ($variables as $variable) {
            $columns[] = $this->quote($variable['column']) . ($variable['kind'] === 'string' ? ' ' . $this->profile->textType() . ' NOT NULL' : ' ' . $this->profile->numericType() . ' NULL');
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
        return $this->profile->quoteIdentifier($identifier);
    }
}
