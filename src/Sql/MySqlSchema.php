<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;

/** MySQL/MariaDB DDL only; it does not claim SAV/ZSAV import/export support. */
final readonly class MySqlSchema
{
    private MySqlProfile $profile;

    public function __construct(private PDO $pdo)
    {
        $this->profile = new MySqlProfile();
    }

    /** @return list<string> */
    public function catalogStatements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS datasets (dataset_name VARCHAR(94) NOT NULL PRIMARY KEY, table_name VARCHAR(64) NOT NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS dataset_weight_variables (dataset_name VARCHAR(94) NOT NULL PRIMARY KEY, variable_ordinal BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS variables (dataset_name VARCHAR(94) NOT NULL, ordinal BIGINT NOT NULL, source_name VARCHAR(191) NOT NULL, column_name VARCHAR(64) NOT NULL, storage_kind VARCHAR(16) NOT NULL, source_width BIGINT NOT NULL, format_family INTEGER NOT NULL, format_width INTEGER NOT NULL, format_decimals INTEGER NOT NULL, write_format_family INTEGER NOT NULL, write_format_width INTEGER NOT NULL, write_format_decimals INTEGER NOT NULL, label LONGTEXT NULL, PRIMARY KEY (dataset_name, ordinal), UNIQUE (dataset_name, column_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS dataset_metadata (dataset_name VARCHAR(94) NOT NULL, meta_key VARCHAR(94) NOT NULL, meta_value LONGTEXT NOT NULL, PRIMARY KEY (dataset_name, meta_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS file_technical_metadata (dataset_name VARCHAR(94) NOT NULL PRIMARY KEY, source_format VARCHAR(16) NOT NULL, record_type VARCHAR(32) NULL, source_version VARCHAR(64) NULL, provenance LONGTEXT NULL, encoding VARCHAR(64) NOT NULL, product_name LONGTEXT NULL, raw_creation_date VARCHAR(16) NULL, raw_creation_time VARCHAR(16) NULL, case_count BIGINT NULL, nominal_case_size BIGINT NULL, layout_code INTEGER NULL, compression INTEGER NULL, compression_bias DOUBLE NULL, machine_code INTEGER NULL, floating_point_representation INTEGER NULL, endianness INTEGER NULL, character_code INTEGER NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS documents (dataset_name VARCHAR(94) NOT NULL, ordinal BIGINT NOT NULL, text LONGTEXT NOT NULL, PRIMARY KEY (dataset_name, ordinal)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS value_labels (dataset_name VARCHAR(94) NOT NULL, variable_ordinal BIGINT NOT NULL, ordinal BIGINT NOT NULL, value_kind VARCHAR(16) NOT NULL, numeric_value DOUBLE NULL, text_value LONGTEXT NULL, label LONGTEXT NOT NULL, PRIMARY KEY (dataset_name, variable_ordinal, ordinal)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS missing_rules (dataset_name VARCHAR(94) NOT NULL, variable_ordinal BIGINT NOT NULL, missing_format INTEGER NOT NULL, PRIMARY KEY (dataset_name, variable_ordinal)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS missing_rule_values (dataset_name VARCHAR(94) NOT NULL, variable_ordinal BIGINT NOT NULL, ordinal BIGINT NOT NULL, value_kind VARCHAR(16) NOT NULL, numeric_value DOUBLE NULL, text_value LONGTEXT NULL, PRIMARY KEY (dataset_name, variable_ordinal, ordinal)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS variable_display_metadata (dataset_name VARCHAR(94) NOT NULL, variable_ordinal BIGINT NOT NULL, measurement_level INTEGER NOT NULL, display_width INTEGER NOT NULL, alignment INTEGER NOT NULL, PRIMARY KEY (dataset_name, variable_ordinal)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS variable_roles (dataset_name VARCHAR(94) NOT NULL, variable_ordinal BIGINT NOT NULL, role INTEGER NOT NULL, PRIMARY KEY (dataset_name, variable_ordinal)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS file_attributes (dataset_name VARCHAR(94) NOT NULL, attribute_name VARCHAR(94) NOT NULL, ordinal BIGINT NOT NULL, value LONGTEXT NOT NULL, PRIMARY KEY (dataset_name, attribute_name, ordinal)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS variable_attributes (dataset_name VARCHAR(94) NOT NULL, variable_ordinal BIGINT NOT NULL, attribute_name VARCHAR(94) NOT NULL, ordinal BIGINT NOT NULL, value LONGTEXT NOT NULL, PRIMARY KEY (dataset_name, variable_ordinal, attribute_name, ordinal)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS variable_sets (dataset_name VARCHAR(94) NOT NULL, set_ordinal BIGINT NOT NULL, name VARCHAR(94) NOT NULL, PRIMARY KEY (dataset_name, set_ordinal), UNIQUE (dataset_name, name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS variable_set_members (dataset_name VARCHAR(94) NOT NULL, set_ordinal BIGINT NOT NULL, member_ordinal BIGINT NOT NULL, variable_ordinal BIGINT NOT NULL, PRIMARY KEY (dataset_name, set_ordinal, member_ordinal)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS multiple_response_sets (dataset_name VARCHAR(94) NOT NULL, set_ordinal BIGINT NOT NULL, name VARCHAR(94) NOT NULL, set_type VARCHAR(16) NOT NULL, label LONGTEXT NULL, counted_value_kind VARCHAR(16) NULL, counted_numeric_value DOUBLE NULL, counted_text_value LONGTEXT NULL, category_labels VARCHAR(16) NOT NULL, label_source VARCHAR(16) NOT NULL, PRIMARY KEY (dataset_name, set_ordinal), UNIQUE (dataset_name, name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS multiple_response_set_members (dataset_name VARCHAR(94) NOT NULL, set_ordinal BIGINT NOT NULL, member_ordinal BIGINT NOT NULL, variable_ordinal BIGINT NOT NULL, PRIMARY KEY (dataset_name, set_ordinal, member_ordinal)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }

    public function createCatalog(): void
    {
        foreach ($this->catalogStatements() as $sql) {
            $this->pdo->exec($sql);
        }
        $this->migrateFormatCatalogue();
    }

    /** Execute explicit nullable migration DDL for pre-format-fidelity catalogues. */
    public function migrateFormatCatalogue(): void
    {
        foreach ($this->formatCatalogueMigrationStatements() as $sql) {
            $this->pdo->exec($sql);
        }
    }

    /** @return list<string> */
    public function formatCatalogueMigrationStatements(): array
    {
        // Old rows cannot reveal write formats, so NULL makes loss explicit.
        return [
            'ALTER TABLE variables ADD COLUMN IF NOT EXISTS write_format_family INTEGER NULL',
            'ALTER TABLE variables ADD COLUMN IF NOT EXISTS write_format_width INTEGER NULL',
            'ALTER TABLE variables ADD COLUMN IF NOT EXISTS write_format_decimals INTEGER NULL',
        ];
    }

    /**
     * @param list<array{name: mixed, type?: mixed}> $sourceVariables
     */
    public function wideTableDefinition(string $datasetName, array $sourceVariables): MySqlWideTableDefinition
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
        return new MySqlWideTableDefinition($tableName, 'CREATE TABLE ' . $this->profile->quoteIdentifier($tableName) . ' (' . implode(', ', $definitions) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', $columns);
    }

    /** @param list<array{name: mixed, type?: mixed}> $sourceVariables */
    public function createWideTable(string $datasetName, array $sourceVariables): MySqlWideTableDefinition
    {
        $definition = $this->wideTableDefinition($datasetName, $sourceVariables);
        $this->pdo->exec($definition->createSql);
        return $definition;
    }
}
