<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;
use PDOStatement;
use Throwable;

/**
 * MySQL/MariaDB importer for the strict one-source-dataset/one-wide-table
 * contract. MySQL DDL commits implicitly, so a failure after DDL is handled
 * with best-effort compensating cleanup rather than a false atomicity claim.
 */
final readonly class MySqlWideTableImporter
{
    public function __construct(private PDO $pdo) {}

    /**
     * @param array<string, mixed> $source
     */
    public function import(array $source, string $datasetName): MySqlWideTableDefinition
    {
        $variables = $source['variables'] ?? null;
        $rows = $source['data'] ?? null;
        if (!is_array($variables) || !array_is_list($variables) || $variables === []) {
            throw new UnsupportedOperation(
                DiagnosticCode::InvalidSourceDataset,
                'The source dataset must contain an ordered variable list.',
            );
        }
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new UnsupportedOperation(
                DiagnosticCode::InvalidSourceDataset,
                'The source dataset must contain an ordered case list.',
            );
        }

        $schema = new MySqlSchema($this->pdo);
        // Complete physical-name and width preflight happens before any DDL.
        $definition = $schema->wideTableDefinition($datasetName, $variables);

        $schema->createCatalog();
        $this->pdo->exec($definition->createSql);

        try {
            $this->pdo->beginTransaction();
            $this->storeCatalogue($datasetName, $variables, $definition);
            $this->insertCases($definition, $rows);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // CREATE TABLE has already committed on MySQL/MariaDB. Remove the
            // target-specific artefacts that this invocation could have left.
            $this->compensateFailure($datasetName, $definition);
            throw $exception;
        }

        return $definition;
    }

    /**
     * @param list<array<string, mixed>> $sourceVariables
     */
    private function storeCatalogue(
        string $datasetName,
        array $sourceVariables,
        MySqlWideTableDefinition $definition,
    ): void {
        $dataset = $this->requiredStatement(
            'INSERT INTO datasets (dataset_name, table_name) VALUES (?, ?)',
            'dataset catalogue',
        );
        $variable = $this->requiredStatement(
            'INSERT INTO variables (dataset_name, ordinal, source_name, column_name, storage_kind, source_width, format_family, format_width, format_decimals, label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'variable catalogue',
        );

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

    /**
     * @param list<mixed> $rows
     */
    private function insertCases(MySqlWideTableDefinition $definition, array $rows): void
    {
        $quote = chr(96);
        $columns = array_merge(['__case_ordinal'], array_column($definition->columns, 'columnName'));
        $quotedColumns = array_map(
            static fn(string $column): string => chr(96) . str_replace(chr(96), chr(96) . chr(96), $column) . chr(96),
            $columns,
        );
        $parameters = array_map(static fn(int $index): string => ':value_' . $index, array_keys($columns));
        $statement = $this->requiredStatement(
            'INSERT INTO ' . $quote . str_replace($quote, $quote . $quote, $definition->tableName) . $quote . ' ('
            . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $parameters) . ')',
            'wide-table data',
        );

        foreach ($rows as $caseOrdinal => $row) {
            if (!is_array($row)) {
                throw new UnsupportedOperation(
                    DiagnosticCode::InvalidSourceDataset,
                    'Every SPSS case must be an ordered value list or source-name map.',
                );
            }
            $values = ['value_0' => $caseOrdinal + 1];
            foreach ($definition->columns as $index => $column) {
                $value = array_key_exists($column['sourceName'], $row)
                    ? $row[$column['sourceName']]
                    : ($row[$index] ?? null);
                $values['value_' . ($index + 1)] = $this->caseValue($value, $column['storageKind']);
            }
            $statement->execute($values);
        }
    }

    private function compensateFailure(string $datasetName, MySqlWideTableDefinition $definition): void
    {
        try {
            $variables = $this->pdo->prepare('DELETE FROM variables WHERE dataset_name = ?');
            if ($variables !== false) {
                $variables->execute([$datasetName]);
            }
            $datasets = $this->pdo->prepare('DELETE FROM datasets WHERE dataset_name = ?');
            if ($datasets !== false) {
                $datasets->execute([$datasetName]);
            }
            $quote = chr(96);
            $this->pdo->exec(
                'DROP TABLE IF EXISTS ' . $quote . str_replace($quote, $quote . $quote, $definition->tableName) . $quote,
            );
        } catch (Throwable) {
            // Preserve the import failure. An operator can still safely rerun
            // after inspecting the deterministic target table name.
        }
    }

    private function requiredStatement(string $sql, string $description): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new UnsupportedOperation(
                DiagnosticCode::InvalidSourceDataset,
                'The MySQL/MariaDB profile could not prepare the ' . $description . ' statement.',
            );
        }

        return $statement;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function integerField(array $source, string $key, int $default): int
    {
        return is_int($source[$key] ?? null) ? $source[$key] : $default;
    }

    private function caseValue(mixed $value, string $storageKind): int|string|null
    {
        if ($storageKind === 'string') {
            if (!is_string($value)) {
                throw new UnsupportedOperation(
                    DiagnosticCode::InvalidSourceDataset,
                    'SPSS string values must be non-null strings.',
                );
            }

            return $value;
        }
        if ($value === null || is_int($value)) {
            return $value;
        }
        if (!is_float($value)) {
            throw new UnsupportedOperation(
                DiagnosticCode::InvalidSourceDataset,
                'SPSS numeric values must be binary64 numbers or system-missing NULL.',
            );
        }

        // A 17-digit decimal representation round-trips every IEEE-754 binary64.
        return sprintf('%.17g', $value);
    }
}
