<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;

/** PostgreSQL DDL only; it does not claim SAV/ZSAV import/export support. */
final readonly class PostgreSqlSchema
{
    private PostgreSqlProfile $profile;

    public function __construct(private PDO $pdo)
    {
        $this->profile = new PostgreSqlProfile();
    }

    /** @return list<string> */
    public function catalogStatements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS datasets (dataset_name TEXT NOT NULL PRIMARY KEY, table_name TEXT NOT NULL UNIQUE)',
            'CREATE TABLE IF NOT EXISTS variables (dataset_name TEXT NOT NULL, ordinal BIGINT NOT NULL, source_name TEXT NOT NULL, column_name TEXT NOT NULL, storage_kind TEXT NOT NULL, source_width BIGINT NOT NULL, format_family INTEGER NOT NULL, format_width INTEGER NOT NULL, format_decimals INTEGER NOT NULL, label TEXT NULL, PRIMARY KEY (dataset_name, ordinal), UNIQUE (dataset_name, column_name))',
            'CREATE TABLE IF NOT EXISTS dataset_metadata (dataset_name TEXT NOT NULL, meta_key TEXT NOT NULL, meta_value TEXT NOT NULL, PRIMARY KEY (dataset_name, meta_key))',
            'CREATE TABLE IF NOT EXISTS file_technical_metadata (dataset_name TEXT NOT NULL PRIMARY KEY, source_format TEXT NOT NULL, record_type TEXT NULL, source_version TEXT NULL, provenance TEXT NULL, encoding TEXT NOT NULL, product_name TEXT NULL, raw_creation_date TEXT NULL, raw_creation_time TEXT NULL, case_count BIGINT NULL, nominal_case_size BIGINT NULL, layout_code INTEGER NULL, compression INTEGER NULL, compression_bias DOUBLE PRECISION NULL, machine_code INTEGER NULL, floating_point_representation INTEGER NULL, endianness INTEGER NULL, character_code INTEGER NULL)',
            'CREATE TABLE IF NOT EXISTS documents (dataset_name TEXT NOT NULL, ordinal BIGINT NOT NULL, text TEXT NOT NULL, PRIMARY KEY (dataset_name, ordinal))',
            'CREATE TABLE IF NOT EXISTS value_labels (dataset_name TEXT NOT NULL, variable_ordinal BIGINT NOT NULL, ordinal BIGINT NOT NULL, value_kind TEXT NOT NULL, numeric_value DOUBLE PRECISION NULL, text_value TEXT NULL, label TEXT NOT NULL, PRIMARY KEY (dataset_name, variable_ordinal, ordinal))',
            'CREATE TABLE IF NOT EXISTS missing_rules (dataset_name TEXT NOT NULL, variable_ordinal BIGINT NOT NULL, missing_format INTEGER NOT NULL, PRIMARY KEY (dataset_name, variable_ordinal))',
            'CREATE TABLE IF NOT EXISTS missing_rule_values (dataset_name TEXT NOT NULL, variable_ordinal BIGINT NOT NULL, ordinal BIGINT NOT NULL, value_kind TEXT NOT NULL, numeric_value DOUBLE PRECISION NULL, text_value TEXT NULL, PRIMARY KEY (dataset_name, variable_ordinal, ordinal))',
            'CREATE TABLE IF NOT EXISTS variable_display_metadata (dataset_name TEXT NOT NULL, variable_ordinal BIGINT NOT NULL, measurement_level INTEGER NOT NULL, display_width INTEGER NOT NULL, alignment INTEGER NOT NULL, PRIMARY KEY (dataset_name, variable_ordinal))',
            'CREATE TABLE IF NOT EXISTS variable_roles (dataset_name TEXT NOT NULL, variable_ordinal BIGINT NOT NULL, role INTEGER NOT NULL, PRIMARY KEY (dataset_name, variable_ordinal))',
            'CREATE TABLE IF NOT EXISTS file_attributes (dataset_name TEXT NOT NULL, attribute_name TEXT NOT NULL, ordinal BIGINT NOT NULL, value TEXT NOT NULL, PRIMARY KEY (dataset_name, attribute_name, ordinal))',
            'CREATE TABLE IF NOT EXISTS variable_attributes (dataset_name TEXT NOT NULL, variable_ordinal BIGINT NOT NULL, attribute_name TEXT NOT NULL, ordinal BIGINT NOT NULL, value TEXT NOT NULL, PRIMARY KEY (dataset_name, variable_ordinal, attribute_name, ordinal))',
            'CREATE TABLE IF NOT EXISTS variable_sets (dataset_name TEXT NOT NULL, set_ordinal BIGINT NOT NULL, name TEXT NOT NULL, PRIMARY KEY (dataset_name, set_ordinal), UNIQUE (dataset_name, name))',
            'CREATE TABLE IF NOT EXISTS variable_set_members (dataset_name TEXT NOT NULL, set_ordinal BIGINT NOT NULL, member_ordinal BIGINT NOT NULL, variable_ordinal BIGINT NOT NULL, PRIMARY KEY (dataset_name, set_ordinal, member_ordinal))',
            'CREATE TABLE IF NOT EXISTS multiple_response_sets (dataset_name TEXT NOT NULL, set_ordinal BIGINT NOT NULL, name TEXT NOT NULL, set_type TEXT NOT NULL, label TEXT NULL, counted_value_kind TEXT NULL, counted_numeric_value DOUBLE PRECISION NULL, counted_text_value TEXT NULL, category_labels TEXT NOT NULL, label_source TEXT NOT NULL, PRIMARY KEY (dataset_name, set_ordinal), UNIQUE (dataset_name, name))',
            'CREATE TABLE IF NOT EXISTS multiple_response_set_members (dataset_name TEXT NOT NULL, set_ordinal BIGINT NOT NULL, member_ordinal BIGINT NOT NULL, variable_ordinal BIGINT NOT NULL, PRIMARY KEY (dataset_name, set_ordinal, member_ordinal))',
        ];
    }

    public function createCatalog(): void
    {
        foreach ($this->catalogStatements() as $sql) {
            $this->pdo->exec($sql);
        }
    }

    /**
     * @param list<array{name: mixed, type?: mixed}> $sourceVariables
     */
    public function wideTableDefinition(string $datasetName, array $sourceVariables): PostgreSqlWideTableDefinition
    {
        if ($datasetName === '') {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The dataset name must not be empty.');
        }
        $this->profile->assertCanRepresent(count($sourceVariables));
        $used = ['__case_ordinal' => true];
        $seenSourceNames = [];
        $columns = [];
        foreach ($sourceVariables as $variable) {
            $sourceName = $variable['name'] ?? null;
            if (!is_string($sourceName) || $sourceName === '' || isset($seenSourceNames[$sourceName])) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Source variable names must be non-empty and unique.');
            }
            $seenSourceNames[$sourceName] = true;
            $columnName = $this->profile->physicalIdentifier($sourceName, $used);
            $used[$columnName] = true;
            $storageKind = is_string($variable['type'] ?? null) && str_contains(strtolower($variable['type']), 'string') ? 'string' : 'numeric';
            $columns[] = ['sourceName' => $sourceName, 'columnName' => $columnName, 'storageKind' => $storageKind];
        }
        $tableName = $this->profile->physicalIdentifier('dataset_' . $datasetName);
        $definitions = [$this->profile->quoteIdentifier('__case_ordinal') . ' BIGINT NOT NULL PRIMARY KEY'];
        foreach ($columns as $column) {
            $type = $column['storageKind'] === 'string' ? $this->profile->textType() . ' NOT NULL' : $this->profile->numericType() . ' NULL';
            $definitions[] = $this->profile->quoteIdentifier($column['columnName']) . ' ' . $type;
        }
        return new PostgreSqlWideTableDefinition($tableName, 'CREATE TABLE ' . $this->profile->quoteIdentifier($tableName) . ' (' . implode(', ', $definitions) . ')', $columns);
    }

    /** @param list<array{name: mixed, type?: mixed}> $sourceVariables */
    public function createWideTable(string $datasetName, array $sourceVariables): PostgreSqlWideTableDefinition
    {
        $definition = $this->wideTableDefinition($datasetName, $sourceVariables);
        $this->pdo->exec($definition->createSql);
        return $definition;
    }
}
