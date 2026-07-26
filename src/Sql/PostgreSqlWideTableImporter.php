<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;
use Throwable;

/**
 * Creates the PostgreSQL side of the strict wide-table contract.
 *
 * This initial slice creates only DDL. It does not insert rows or catalogue
 * records, and therefore does not claim PostgreSQL import/export support.
 */
final readonly class PostgreSqlWideTableImporter
{
    public function __construct(private PDO $pdo) {}

    /** @param array<string, mixed> $source */
    public function createTables(array $source, string $datasetName): PostgreSqlWideTableDefinition
    {
        $variables = $source['variables'] ?? null;
        if (!is_array($variables) || !array_is_list($variables) || $variables === []) {
            throw new UnsupportedOperation(
                DiagnosticCode::InvalidSourceDataset,
                'The source dataset must contain an ordered variable list.',
            );
        }

        $schema = new PostgreSqlSchema($this->pdo);
        // Validate the complete physical mapping before any DDL starts.
        $definition = $schema->wideTableDefinition($datasetName, $variables);

        $this->pdo->beginTransaction();
        try {
            $schema->createCatalog();
            $this->pdo->exec($definition->createSql);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        return $definition;
    }
    /**
     * Store the normalized cases and core dictionary in one strict
     * PostgreSQL wide table. Export remains intentionally unavailable.
     *
     * @param array<string, mixed> $source
     */
    public function import(array $source, string $datasetName): PostgreSqlWideTableDefinition
    {
        $variables = $source['variables'] ?? null;
        $rows = $source['data'] ?? null;
        if (!is_array($variables) || !array_is_list($variables) || $variables === []) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The source dataset must contain an ordered variable list.');
        }
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The source dataset must contain an ordered case list.');
        }

        $schema = new PostgreSqlSchema($this->pdo);
        // Preflight the entire physical-name mapping before changing the target.
        $definition = $schema->wideTableDefinition($datasetName, $variables);

        $this->pdo->beginTransaction();
        try {
            $schema->createCatalog();
            $this->pdo->exec($definition->createSql);
            $this->storeCatalogue($datasetName, $variables, $definition);
            $this->insertCases($definition, $rows);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return $definition;
    }

    /** @param list<array<string, mixed>> $sourceVariables */
    private function storeCatalogue(string $datasetName, array $sourceVariables, PostgreSqlWideTableDefinition $definition): void
    {
        $dataset = $this->pdo->prepare('INSERT INTO datasets (dataset_name, table_name) VALUES (?, ?)');
        $variable = $this->pdo->prepare('INSERT INTO variables (dataset_name, ordinal, source_name, column_name, storage_kind, source_width, format_family, format_width, format_decimals, label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if ($dataset === false || $variable === false) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The PostgreSQL profile could not prepare a required catalogue statement.');
        }

        $dataset->execute([$datasetName, $definition->tableName]);
        foreach ($definition->columns as $index => $column) {
            $source = $sourceVariables[$index];
            $variable->execute([
                $datasetName,
                $index + 1,
                $column['sourceName'],
                $column['columnName'],
                $column['storageKind'],
                $this->integerField($source, 'width', 0),
                $this->integerField($source, 'formatFamily', 5),
                $this->integerField($source, 'formatWidth', 8),
                $this->integerField($source, 'formatDecimals', 0),
                is_string($source['label'] ?? null) ? $source['label'] : null,
            ]);
        }
    }

    /** @param list<mixed> $rows */
    private function insertCases(PostgreSqlWideTableDefinition $definition, array $rows): void
    {
        $columns = array_merge(['__case_ordinal'], array_column($definition->columns, 'columnName'));
        $quoted = array_map(fn(string $column): string => '"' . str_replace('"', '""', $column) . '"', $columns);
        $parameters = array_map(static fn(int $index): string => ':value_' . $index, array_keys($columns));
        $statement = $this->pdo->prepare(
            'INSERT INTO "' . str_replace('"', '""', $definition->tableName) . '" (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', $parameters) . ')',
        );
        if ($statement === false) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The PostgreSQL profile could not prepare a required data statement.');
        }

        foreach ($rows as $caseOrdinal => $row) {
            if (!is_array($row)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every SPSS case must be an ordered value list or source-name map.');
            }
            $values = ['value_0' => $caseOrdinal + 1];
            foreach ($definition->columns as $index => $column) {
                $value = array_key_exists($column['sourceName'], $row) ? $row[$column['sourceName']] : ($row[$index] ?? null);
                $values['value_' . ($index + 1)] = $this->caseValue($value, $column['storageKind']);
            }
            $statement->execute($values);
        }
    }

    /** @param array<string, mixed> $source */
    private function integerField(array $source, string $key, int $default): int
    {
        return is_int($source[$key] ?? null) ? $source[$key] : $default;
    }

    private function caseValue(mixed $value, string $storageKind): int|string|null
    {
        if ($storageKind === 'string') {
            if (!is_string($value)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SPSS string values must be non-null strings.');
            }
            return $value;
        }
        if ($value === null || is_int($value)) {
            return $value;
        }
        if (!is_float($value)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SPSS numeric values must be binary64 numbers or system-missing NULL.');
        }

        // 17 significant digits are sufficient to round-trip every binary64.
        return sprintf('%.17g', $value);
    }

}
