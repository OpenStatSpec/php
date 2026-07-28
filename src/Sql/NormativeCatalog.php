<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\Binary64;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\SpssMissingValueSentinel;
use PDO;

/**
 * The normative OpenStatSpec catalogue. Legacy plural catalogue tables remain
 * temporarily readable by existing exporters, but every new import writes this
 * source-faithful contract as the authoritative portable representation.
 */
final readonly class NormativeCatalog
{
    private const SPEC_VERSION = '1.0';

    public function __construct(private PDO $pdo) {}

    public function createTables(): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql' && $this->pdo->inTransaction()) {
            // MySQL and MariaDB implicitly commit DDL, even with IF NOT EXISTS.
            // OperationJournal creates the catalogue before the import transaction.
            return;
        }
        CatalogOwnership::ensure($this->pdo);
        $binary64 = $driver === 'pgsql' ? 'DOUBLE PRECISION' : 'DOUBLE';

        foreach ([
            'CREATE TABLE IF NOT EXISTS openstatspec_schema_migration (version INTEGER NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL)',
            'CREATE TABLE IF NOT EXISTS dataset (dataset_id VARCHAR(36) NOT NULL PRIMARY KEY, spec_version VARCHAR(32) NOT NULL, source_format VARCHAR(16) NOT NULL, physical_table_schema TEXT NULL, physical_table_name TEXT NOT NULL, dataset_name TEXT NULL, dataset_label TEXT NULL, source_encoding TEXT NULL, source_hash VARCHAR(128) NULL, source_case_count BIGINT NOT NULL, imported_at TIMESTAMP NOT NULL)',
            'CREATE TABLE IF NOT EXISTS variable (variable_id VARCHAR(36) NOT NULL PRIMARY KEY, dataset_id VARCHAR(36) NOT NULL, source_ordinal INTEGER NOT NULL, source_name VARCHAR(255) NOT NULL, physical_name VARCHAR(255) NOT NULL, storage_kind VARCHAR(16) NOT NULL, declared_string_width INTEGER NULL, variable_label TEXT NULL, print_format_family TEXT NULL, print_format_width INTEGER NULL, print_format_decimals INTEGER NULL, write_format_family TEXT NULL, write_format_width INTEGER NULL, write_format_decimals INTEGER NULL, measurement_level TEXT NULL, variable_role TEXT NULL, display_width INTEGER NULL, display_alignment TEXT NULL, UNIQUE (dataset_id, source_ordinal), UNIQUE (dataset_id, source_name), UNIQUE (dataset_id, physical_name), FOREIGN KEY (dataset_id) REFERENCES dataset(dataset_id))',
            'CREATE TABLE IF NOT EXISTS dataset_weight_variable (dataset_id VARCHAR(36) NOT NULL PRIMARY KEY, variable_id VARCHAR(36) NOT NULL UNIQUE, FOREIGN KEY (dataset_id) REFERENCES dataset(dataset_id), FOREIGN KEY (variable_id) REFERENCES variable(variable_id))',
            'CREATE TABLE IF NOT EXISTS value_label_set (value_label_set_id VARCHAR(36) NOT NULL PRIMARY KEY, dataset_id VARCHAR(36) NOT NULL, name TEXT NULL, FOREIGN KEY (dataset_id) REFERENCES dataset(dataset_id))',
            'CREATE TABLE IF NOT EXISTS value_label (value_label_id VARCHAR(36) NOT NULL PRIMARY KEY, value_label_set_id VARCHAR(36) NOT NULL, ordinal INTEGER NOT NULL, code_kind VARCHAR(16) NOT NULL, numeric_code __BINARY64__ NULL, string_code TEXT NULL, label TEXT NOT NULL, UNIQUE (value_label_set_id, ordinal), FOREIGN KEY (value_label_set_id) REFERENCES value_label_set(value_label_set_id))',
            'CREATE TABLE IF NOT EXISTS variable_value_label_set (variable_id VARCHAR(36) NOT NULL PRIMARY KEY, value_label_set_id VARCHAR(36) NOT NULL, FOREIGN KEY (variable_id) REFERENCES variable(variable_id), FOREIGN KEY (value_label_set_id) REFERENCES value_label_set(value_label_set_id))',
            'CREATE TABLE IF NOT EXISTS missing_rule (missing_rule_id VARCHAR(36) NOT NULL PRIMARY KEY, variable_id VARCHAR(36) NOT NULL, ordinal INTEGER NOT NULL, rule_kind VARCHAR(32) NOT NULL, code_kind VARCHAR(16) NULL, numeric_value __BINARY64__ NULL, string_value TEXT NULL, numeric_lower __BINARY64__ NULL, numeric_upper __BINARY64__ NULL, lower_special VARCHAR(16) NULL, upper_special VARCHAR(16) NULL, UNIQUE (variable_id, ordinal), FOREIGN KEY (variable_id) REFERENCES variable(variable_id))',
            'CREATE TABLE IF NOT EXISTS dataset_attribute (dataset_attribute_id VARCHAR(36) NOT NULL PRIMARY KEY, dataset_id VARCHAR(36) NOT NULL, attribute_name VARCHAR(255) NOT NULL, array_ordinal INTEGER NOT NULL, attribute_value TEXT NOT NULL, UNIQUE (dataset_id, attribute_name, array_ordinal), FOREIGN KEY (dataset_id) REFERENCES dataset(dataset_id))',
            'CREATE TABLE IF NOT EXISTS variable_attribute (variable_attribute_id VARCHAR(36) NOT NULL PRIMARY KEY, variable_id VARCHAR(36) NOT NULL, attribute_name VARCHAR(255) NOT NULL, array_ordinal INTEGER NOT NULL, attribute_value TEXT NOT NULL, UNIQUE (variable_id, attribute_name, array_ordinal), FOREIGN KEY (variable_id) REFERENCES variable(variable_id))',
            'CREATE TABLE IF NOT EXISTS document (document_id VARCHAR(36) NOT NULL PRIMARY KEY, dataset_id VARCHAR(36) NOT NULL, source_ordinal INTEGER NOT NULL, document_text TEXT NOT NULL, UNIQUE (dataset_id, source_ordinal), FOREIGN KEY (dataset_id) REFERENCES dataset(dataset_id))',
            'CREATE TABLE IF NOT EXISTS variable_set (variable_set_id VARCHAR(36) NOT NULL PRIMARY KEY, dataset_id VARCHAR(36) NOT NULL, source_ordinal INTEGER NOT NULL, set_name VARCHAR(255) NOT NULL, UNIQUE (dataset_id, source_ordinal), UNIQUE (dataset_id, set_name), FOREIGN KEY (dataset_id) REFERENCES dataset(dataset_id))',
            'CREATE TABLE IF NOT EXISTS variable_set_member (variable_set_id VARCHAR(36) NOT NULL, variable_id VARCHAR(36) NOT NULL, source_ordinal INTEGER NOT NULL, PRIMARY KEY (variable_set_id, source_ordinal), UNIQUE (variable_set_id, variable_id), FOREIGN KEY (variable_set_id) REFERENCES variable_set(variable_set_id), FOREIGN KEY (variable_id) REFERENCES variable(variable_id))',
            'CREATE TABLE IF NOT EXISTS multiple_response_set (multiple_response_set_id VARCHAR(36) NOT NULL PRIMARY KEY, dataset_id VARCHAR(36) NOT NULL, source_ordinal INTEGER NOT NULL, set_name VARCHAR(255) NOT NULL, set_label TEXT NULL, set_kind VARCHAR(4) NOT NULL, counted_value_kind VARCHAR(16) NULL, counted_numeric_value __BINARY64__ NULL, counted_string_value TEXT NULL, category_label_behavior TEXT NULL, label_source TEXT NULL, UNIQUE (dataset_id, source_ordinal), UNIQUE (dataset_id, set_name), FOREIGN KEY (dataset_id) REFERENCES dataset(dataset_id))',
            'CREATE TABLE IF NOT EXISTS multiple_response_member (multiple_response_set_id VARCHAR(36) NOT NULL, variable_id VARCHAR(36) NOT NULL, source_ordinal INTEGER NOT NULL, PRIMARY KEY (multiple_response_set_id, source_ordinal), UNIQUE (multiple_response_set_id, variable_id), FOREIGN KEY (multiple_response_set_id) REFERENCES multiple_response_set(multiple_response_set_id), FOREIGN KEY (variable_id) REFERENCES variable(variable_id))',
            'CREATE TABLE IF NOT EXISTS operation (operation_id VARCHAR(36) NOT NULL PRIMARY KEY, operation_kind VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, source_format VARCHAR(16) NULL, started_at TIMESTAMP NOT NULL, completed_at TIMESTAMP NULL)',
            'CREATE TABLE IF NOT EXISTS fidelity_event (fidelity_event_id VARCHAR(36) NOT NULL PRIMARY KEY, operation_id VARCHAR(36) NOT NULL, dataset_id VARCHAR(36) NULL, direction VARCHAR(16) NOT NULL, severity VARCHAR(16) NOT NULL, event_code VARCHAR(96) NOT NULL, source_item TEXT NULL, detail_json TEXT NOT NULL, created_at TIMESTAMP NOT NULL, FOREIGN KEY (operation_id) REFERENCES operation(operation_id), FOREIGN KEY (dataset_id) REFERENCES dataset(dataset_id))',
        ] as $statement) {
            $this->pdo->exec(str_replace('__BINARY64__', $binary64, $statement));
        }
        $migrationV3Required = !$this->migrationApplied(3);
        if ($migrationV3Required) {
            $this->ensureColumn('variable_set', 'source_ordinal', 'INTEGER NULL');
            $this->ensureColumn('multiple_response_set', 'source_ordinal', 'INTEGER NULL');
            $this->ensureColumn('multiple_response_set', 'counted_value_kind', 'VARCHAR(16) NULL');
            $this->ensureColumn('multiple_response_set', 'counted_string_value', 'TEXT NULL');
            $this->ensureColumn('multiple_response_set', 'label_source', 'TEXT NULL');
            $this->backfillSetOrdinals('variable_set', 'variable_set_id', 'variable_sets');
            $this->backfillSetOrdinals('multiple_response_set', 'multiple_response_set_id', 'multiple_response_sets');
            $this->enforceSetOrdinalConstraints($driver);
        }

        $migration = match ($driver) {
            'mysql' => 'INSERT IGNORE INTO openstatspec_schema_migration (version, applied_at) VALUES (?, ?)',
            'pgsql', 'sqlite' => 'INSERT INTO openstatspec_schema_migration (version, applied_at) VALUES (?, ?) ON CONFLICT (version) DO NOTHING',
            default => null,
        };
        if ($migration !== null) {
            foreach ([1, 2] as $version) {
                $this->pdo->prepare($migration)->execute([$version, self::timestamp()]);
            }
            if ($migrationV3Required) {
                $this->pdo->prepare($migration)->execute([3, self::timestamp()]);
            }
        }
    }

    private function migrationApplied(int $version): bool
    {
        $statement = $this->statement('SELECT 1 FROM openstatspec_schema_migration WHERE version = ?');
        $statement->execute([$version]);
        return $statement->fetchColumn() !== false;
    }

    public function hasDataset(string $datasetName): bool
    {
        $this->createTables();
        $statement = $this->statement("SELECT 1 FROM dataset WHERE dataset_name = ?");
        $statement->execute([$datasetName]);
        return $statement->fetchColumn() !== false;
    }


    /** @param array<string, mixed> $source */
    public function storeImportedDataset(string $datasetName, string $sourcePath, array $source): void
    {
        $this->createTables();
        $mappings = $this->physicalVariables($datasetName);
        /** @var list<mixed> $variables */
        $variables = $this->list($source['variables'] ?? null, 'Source variables');
        if (count($variables) !== count($mappings)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The physical variable catalogue does not match the source dictionary.');
        }

        $datasetId = self::uuid();
        $technical = is_array($source['technicalMetadata'] ?? null) ? $source['technicalMetadata'] : [];
        $sourceFormat = $technical['sourceFormat'] ?? null;
        if (!is_string($sourceFormat) || $sourceFormat === '') {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The source format is required by the OpenStatSpec catalogue.');
        }
        $tableName = $this->physicalTableName($datasetName);
        $this->statement('INSERT INTO dataset (dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, dataset_name, dataset_label, source_encoding, source_hash, source_case_count, imported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $datasetId,
            self::SPEC_VERSION,
            $sourceFormat,
            null,
            $tableName,
            $datasetName,
            is_string($source['fileLabel'] ?? null) ? $source['fileLabel'] : null,
            is_string($technical['encoding'] ?? null) ? $technical['encoding'] : null,
            is_file($sourcePath) ? hash_file('sha256', $sourcePath) : null,
            count($this->list($source['data'] ?? null, 'Source cases')),
            self::timestamp(),
        ]);

        $variableIds = [];
        foreach ($variables as $index => $variable) {
            if (!is_array($variable) || !isset($mappings[$index])) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every source variable needs a physical column mapping.');
            }
            $variableIds[$index + 1] = self::uuid();
            $display = $this->displayAt($source, $index);
            $this->statement('INSERT INTO variable (variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind, declared_string_width, variable_label, print_format_family, print_format_width, print_format_decimals, write_format_family, write_format_width, write_format_decimals, measurement_level, variable_role, display_width, display_alignment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $variableIds[$index + 1],
                $datasetId,
                $index + 1,
                $this->string($variable['name'] ?? null, 'Source variable name'),
                $mappings[$index],
                $this->storageKind($variable['type'] ?? null),
                $this->storageKind($variable['type'] ?? null) === 'string' ? $this->integer($variable['width'] ?? null) : null,
                is_string($variable['label'] ?? null) ? $variable['label'] : null,
                $this->format($variable['formatFamily'] ?? null),
                $this->integer($variable['formatWidth'] ?? null),
                $this->integer($variable['formatDecimals'] ?? null),
                $this->format($variable['writeFormatFamily'] ?? null),
                $this->integer($variable['writeFormatWidth'] ?? null),
                $this->integer($variable['writeFormatDecimals'] ?? null),
                isset($display['measure']) ? (string) $display['measure'] : null,
                isset($variable['role']) ? (string) $variable['role'] : null,
                $this->integer($display['columns'] ?? null),
                isset($display['alignment']) ? (string) $display['alignment'] : null,
            ]);
            $this->storeMissingRules($variableIds[$index + 1], $variable);
            $this->storeVariableAttributes($variableIds[$index + 1], $variable['attributes'] ?? []);
        }

        $this->storeWeightVariable($datasetId, $variableIds, $source['weightVariableName'] ?? null, $variables);
        $this->storeValueLabels($datasetId, $variableIds, $this->list($source['valueLabels'] ?? [], 'Value labels'));
        $this->storeDatasetAttributes($datasetId, $this->list($source['fileAttributes'] ?? [], 'Dataset attributes'));
        $this->storeDocuments($datasetId, $this->list($source['documents'] ?? [], 'Documents'));
        $this->storeVariableSets($datasetId, $variableIds, $this->list($source['variableSets'] ?? [], 'Variable sets'), $variables);
        $this->storeMultipleResponseSets($datasetId, $variableIds, $this->list($source['multipleResponseSets'] ?? [], 'Multiple-response sets'), $variables);
    }

    private function physicalTableName(string $datasetName): string
    {
        $statement = $this->statement('SELECT table_name FROM datasets WHERE dataset_name = ?');
        $statement->execute([$datasetName]);
        $name = $statement->fetchColumn();
        if (!is_string($name) || $name === '') {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The physical source table was not catalogued.');
        }

        return $name;
    }

    /** @return list<string> */
    private function physicalVariables(string $datasetName): array
    {
        $statement = $this->statement('SELECT column_name FROM variables WHERE dataset_name = ? ORDER BY ordinal');
        $statement->execute([$datasetName]);
        $names = $statement->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_map(static fn(mixed $name): string => (string) $name, $names));
    }
    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function displayAt(array $source, int $index): array
    {
        $displays = $source['displayParameters'] ?? [];
        return is_array($displays) && is_array($displays[$index] ?? null) ? $displays[$index] : [];
    }

    /**
     * @param array<int, string> $variableIds
     * @param list<mixed> $sets
     */
    private function storeValueLabels(string $datasetId, array $variableIds, array $sets): void
    {
        foreach ($sets as $set) {
            if (!is_array($set)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Value-label sets must be arrays.');
            }
            $valueLabelSetId = self::uuid();
            $this->statement('INSERT INTO value_label_set (value_label_set_id, dataset_id, name) VALUES (?, ?, ?)')->execute([$valueLabelSetId, $datasetId, null]);
            foreach ($this->list($set['labels'] ?? null, 'Value labels') as $ordinal => $label) {
                if (!is_array($label) || !array_key_exists('value', $label) || !is_string($label['label'] ?? null)) {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A value label must have a typed code and text.');
                }
                $value = $label['value'];
                $this->statement('INSERT INTO value_label (value_label_id, value_label_set_id, ordinal, code_kind, numeric_code, string_code, label) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
                    self::uuid(), $valueLabelSetId, $ordinal + 1, is_string($value) ? 'string' : 'numeric', is_string($value) ? null : Binary64::encode($value), is_string($value) ? $value : null, $label['label'],
                ]);
            }
            foreach ($this->list($set['indexes'] ?? null, 'Value-label variable indexes') as $index) {
                $ordinal = is_int($index) ? $index + 1 : null;
                if ($ordinal === null || !isset($variableIds[$ordinal])) {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A value-label set references an unknown variable.');
                }
                $this->statement('INSERT INTO variable_value_label_set (variable_id, value_label_set_id) VALUES (?, ?)')->execute([$variableIds[$ordinal], $valueLabelSetId]);
            }
        }
    }

    /** @param array<string, mixed> $variable */
    private function storeMissingRules(string $variableId, array $variable): void
    {
        $format = $this->integer($variable['missingFormat'] ?? null) ?? 0;
        $values = $this->list($variable['missingValues'] ?? [], 'Missing values');
        if ($format === 0) {
            return;
        }
        if ($format > 0) {
            foreach ($values as $ordinal => $value) {
                $this->storeDiscreteMissingRule($variableId, $ordinal + 1, $value);
            }
            return;
        }
        if (!in_array($format, [-2, -3], true) || count($values) < 2) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The source missing-value rule is malformed.');
        }
        [$lower, $lowerSpecial] = $this->rangeEndpoint($values[0], true);
        [$upper, $upperSpecial] = $this->rangeEndpoint($values[1], false);
        $this->statement('INSERT INTO missing_rule (missing_rule_id, variable_id, ordinal, rule_kind, code_kind, numeric_value, string_value, numeric_lower, numeric_upper, lower_special, upper_special) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            self::uuid(), $variableId, 1, 'numeric_range', null, null, null, $lower, $upper, $lowerSpecial, $upperSpecial,
        ]);
        if ($format === -3) {
            $this->storeDiscreteMissingRule($variableId, 2, $values[2] ?? null);
        }
    }

    private function storeDiscreteMissingRule(string $variableId, int $ordinal, mixed $value): void
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A discrete missing value must be numeric or string.');
        }
        $this->statement('INSERT INTO missing_rule (missing_rule_id, variable_id, ordinal, rule_kind, code_kind, numeric_value, string_value, numeric_lower, numeric_upper, lower_special, upper_special) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            self::uuid(), $variableId, $ordinal, 'discrete', is_string($value) ? 'string' : 'numeric', is_string($value) ? null : Binary64::encode($value), is_string($value) ? $value : null, null, null, null, null,
        ]);
    }

    /** @param list<mixed> $attributes */
    private function storeDatasetAttributes(string $datasetId, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            if (!is_array($attribute) || !is_string($attribute['name'] ?? null)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A dataset attribute needs a name.');
            }
            foreach ($this->list($attribute['values'] ?? null, 'Dataset attribute values') as $ordinal => $value) {
                if (!is_string($value)) {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Dataset attribute values must be strings.');
                }
                $this->statement('INSERT INTO dataset_attribute (dataset_attribute_id, dataset_id, attribute_name, array_ordinal, attribute_value) VALUES (?, ?, ?, ?, ?)')->execute([self::uuid(), $datasetId, $attribute['name'], $ordinal + 1, $value]);
            }
        }
    }

    /** @param list<mixed> $attributes */
    private function storeVariableAttributes(string $variableId, mixed $attributes): void
    {
        foreach ($this->list($attributes, 'Variable attributes') as $attribute) {
            if (!is_array($attribute) || !is_string($attribute['name'] ?? null)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A variable attribute needs a name.');
            }
            foreach ($this->list($attribute['values'] ?? null, 'Variable attribute values') as $ordinal => $value) {
                if (!is_string($value)) {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Variable attribute values must be strings.');
                }
                $this->statement('INSERT INTO variable_attribute (variable_attribute_id, variable_id, attribute_name, array_ordinal, attribute_value) VALUES (?, ?, ?, ?, ?)')->execute([self::uuid(), $variableId, $attribute['name'], $ordinal + 1, $value]);
            }
        }
    }

    /** @param list<mixed> $documents */
    private function storeDocuments(string $datasetId, array $documents): void
    {
        foreach ($documents as $ordinal => $document) {
            if (!is_string($document)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Document lines must be strings.');
            }
            $this->statement('INSERT INTO document (document_id, dataset_id, source_ordinal, document_text) VALUES (?, ?, ?, ?)')->execute([self::uuid(), $datasetId, $ordinal + 1, $document]);
        }
    }

    /**
     * @param array<int, string> $variableIds
     * @param list<mixed> $sets
     * @param list<mixed> $variables
     */
    private function storeVariableSets(string $datasetId, array $variableIds, array $sets, array $variables): void
    {
        foreach ($sets as $setOrdinal => $set) {
            if (!is_array($set) || !is_string($set['name'] ?? null)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A variable set needs a name.');
            }
            $setId = self::uuid();
            $this->statement('INSERT INTO variable_set (variable_set_id, dataset_id, source_ordinal, set_name) VALUES (?, ?, ?, ?)')->execute([$setId, $datasetId, $setOrdinal + 1, $set['name']]);
            foreach ($this->list($set['variableNames'] ?? null, 'Variable-set members') as $ordinal => $name) {
                $variableOrdinal = $this->variableOrdinal($variables, $name);
                $this->statement('INSERT INTO variable_set_member (variable_set_id, variable_id, source_ordinal) VALUES (?, ?, ?)')->execute([$setId, $variableIds[$variableOrdinal], $ordinal + 1]);
            }
        }
    }

    /**
     * @param array<int, string> $variableIds
     * @param list<mixed> $sets
     * @param list<mixed> $variables
     */
    private function storeMultipleResponseSets(string $datasetId, array $variableIds, array $sets, array $variables): void
    {
        foreach ($sets as $setOrdinal => $set) {
            if (!is_array($set) || !is_string($set['name'] ?? null) || !is_string($set['type'] ?? null)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A multiple-response set needs a name and kind.');
            }
            $setId = self::uuid();
            $counted = $set['countedValue'] ?? null;
            $countedKind = is_string($counted) ? 'string' : (is_int($counted) || is_float($counted) ? 'numeric' : null);
            $this->statement('INSERT INTO multiple_response_set (multiple_response_set_id, dataset_id, source_ordinal, set_name, set_label, set_kind, counted_value_kind, counted_numeric_value, counted_string_value, category_label_behavior, label_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $setId, $datasetId, $setOrdinal + 1, $set['name'], is_string($set['label'] ?? null) ? $set['label'] : null, $set['type'] === 'dichotomy' ? 'MD' : 'MC', $countedKind, is_int($counted) || is_float($counted) ? $counted : null, is_string($counted) ? $counted : null, is_string($set['categoryLabels'] ?? null) ? $set['categoryLabels'] : null, is_string($set['labelSource'] ?? null) ? $set['labelSource'] : null,
            ]);
            foreach ($this->list($set['variableNames'] ?? null, 'Multiple-response members') as $ordinal => $name) {
                $variableOrdinal = $this->variableOrdinal($variables, $name);
                $this->statement('INSERT INTO multiple_response_member (multiple_response_set_id, variable_id, source_ordinal) VALUES (?, ?, ?)')->execute([$setId, $variableIds[$variableOrdinal], $ordinal + 1]);
            }
        }
    }

    /**
     * @param array<int, string> $variableIds
     * @param list<mixed> $variables
     */
    private function storeWeightVariable(string $datasetId, array $variableIds, mixed $name, array $variables): void
    {
        if ($name === null) {
            return;
        }
        $ordinal = $this->variableOrdinal($variables, $name);
        $this->statement('INSERT INTO dataset_weight_variable (dataset_id, variable_id) VALUES (?, ?)')->execute([$datasetId, $variableIds[$ordinal]]);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function rangeEndpoint(mixed $value, bool $lower): array
    {
        if (!is_int($value) && !is_float($value)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A numeric missing-value range endpoint must be numeric.');
        }
        if ($lower && SpssMissingValueSentinel::isLowest($value)) {
            return [null, 'LOWEST'];
        }
        if (!$lower && SpssMissingValueSentinel::isHighest($value)) {
            return [null, 'HIGHEST'];
        }
        return [Binary64::encode($value), null];
    }

    /** @param list<mixed> $variables */
    private function variableOrdinal(array $variables, mixed $name): int
    {
        if (!is_string($name)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A set member must name a source variable.');
        }
        foreach ($variables as $index => $variable) {
            if (is_array($variable) && ($variable['name'] ?? null) === $name) {
                return $index + 1;
            }
        }
        throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A set member references an unknown source variable.');
    }

    /** @return list<mixed> */
    private function list(mixed $value, string $description): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, $description . ' must be an ordered list.');
        }
        return $value;
    }

    private function storageKind(mixed $type): string
    {
        return is_string($type) && str_contains(strtolower($type), 'string') ? 'string' : 'numeric';
    }

    private function string(mixed $value, string $description): string
    {
        if (!is_string($value) || $value === '') {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, $description . ' is required.');
        }
        return $value;
    }

    private function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function format(mixed $value): ?string
    {
        return is_int($value) || is_string($value) ? (string) $value : null;
    }

    private function backfillSetOrdinals(string $table, string $idColumn, string $legacyTable): void
    {
        $rows = $this->pdo->query('SELECT source.' . $idColumn . ', source.dataset_id, source.set_name, dataset.dataset_name FROM ' . $table . ' source JOIN dataset ON dataset.dataset_id = source.dataset_id WHERE source.source_ordinal IS NULL ORDER BY source.dataset_id, source.set_name');
        if ($rows === false) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Could not inspect ' . $table . ' for ordinal migration.');
        }
        $pending = $rows->fetchAll(PDO::FETCH_ASSOC);
        if ($pending === []) {
            return;
        }
        $legacy = $this->statement('SELECT set_ordinal FROM ' . $legacyTable . ' WHERE dataset_name = ? AND name = ?');
        $update = $this->statement('UPDATE ' . $table . ' SET source_ordinal = ? WHERE ' . $idColumn . ' = ?');
        foreach ($pending as $row) {
            $legacy->execute([$row['dataset_name'], $row['set_name']]);
            $ordinals = $legacy->fetchAll(PDO::FETCH_COLUMN);
            if (count($ordinals) !== 1 || (int) $ordinals[0] < 1) {
                throw new UnsupportedOperation(
                    DiagnosticCode::InvalidSourceDataset,
                    'The source order for legacy ' . $table . ' entry ' . (string) $row['set_name'] . ' cannot be reconstructed unambiguously.',
                );
            }
            $update->execute([(int) $ordinals[0], $row[$idColumn]]);
        }
    }

    private function enforceSetOrdinalConstraints(string $driver): void
    {
        foreach (['variable_set', 'multiple_response_set'] as $table) {
            $this->validateSetOrdinals($table);
        }

        if ($driver === 'sqlite') {
            $this->migrateSqliteSetConstraints();
            return;
        }
        if ($driver === 'pgsql') {
            $ownsTransaction = !$this->pdo->inTransaction();
            if ($ownsTransaction) {
                $this->pdo->beginTransaction();
            }
            try {
                $this->pdo->exec('ALTER TABLE variable_set ALTER COLUMN source_ordinal SET NOT NULL');
                $this->pdo->exec('ALTER TABLE multiple_response_set ALTER COLUMN source_ordinal SET NOT NULL');
                $this->ensurePostgreSqlUniqueIndex('variable_set', 'uq_variable_set_dataset_ordinal');
                $this->ensurePostgreSqlUniqueIndex('multiple_response_set', 'uq_multiple_response_set_dataset_ordinal');
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
            } catch (\Throwable $exception) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
            return;
        }
        if ($driver === 'mysql') {
            // MySQL/MariaDB DDL is not transactional. Every statement is
            // idempotent and migration version 3 is recorded only after all
            // constraints have been restored successfully.
            $this->pdo->exec('ALTER TABLE variable_set MODIFY source_ordinal INTEGER NOT NULL');
            $this->pdo->exec('ALTER TABLE multiple_response_set MODIFY source_ordinal INTEGER NOT NULL');
            $this->ensureMySqlUniqueIndex('variable_set', 'uq_variable_set_dataset_ordinal');
            $this->ensureMySqlUniqueIndex('multiple_response_set', 'uq_multiple_response_set_dataset_ordinal');
        }
    }

    private function validateSetOrdinals(string $table): void
    {
        $invalid = $this->pdo->query('SELECT 1 FROM ' . $table . ' WHERE source_ordinal IS NULL LIMIT 1');
        if ($invalid !== false && $invalid->fetchColumn() !== false) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, $table . ' contains a missing source ordinal and cannot be migrated.');
        }
        $duplicate = $this->pdo->query('SELECT 1 FROM ' . $table . ' GROUP BY dataset_id, source_ordinal HAVING COUNT(*) > 1 LIMIT 1');
        if ($duplicate !== false && $duplicate->fetchColumn() !== false) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, $table . ' contains duplicate source ordinals and cannot be migrated.');
        }
    }

    private function migrateSqliteSetConstraints(): void
    {
        $tables = array_values(array_filter(
            ['variable_set', 'multiple_response_set'],
            fn(string $table): bool => $this->sqliteSetTableRequiresRebuild($table),
        ));
        if ($tables === []) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Run migrateCatalog() before importing into a legacy SQLite catalog.');
        }

        $foreignKeyStatement = $this->pdo->query('PRAGMA foreign_keys');
        if ($foreignKeyStatement === false) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Could not inspect SQLite foreign-key mode.');
        }
        $foreignKeys = (int) $foreignKeyStatement->fetchColumn();
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->pdo->beginTransaction();
        try {
            foreach ($tables as $table) {
                $this->rebuildSqliteSetTable($table);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        } finally {
            $this->pdo->exec('PRAGMA foreign_keys = ' . ($foreignKeys === 1 ? 'ON' : 'OFF'));
        }

        $violations = $this->pdo->query('PRAGMA foreign_key_check');
        if ($violations === false || $violations->fetchColumn() !== false) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SQLite set migration produced a foreign-key violation.');
        }
    }

    private function sqliteSetTableRequiresRebuild(string $table): bool
    {
        $columns = $this->pdo->query('PRAGMA table_info(' . $table . ')');
        if ($columns === false) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Could not inspect ' . $table . ' for migration.');
        }
        foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (($column['name'] ?? null) === 'source_ordinal') {
                return (int) ($column['notnull'] ?? 0) !== 1;
            }
        }
        throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, $table . ' has no source ordinal after migration preparation.');
    }

    private function rebuildSqliteSetTable(string $table): void
    {
        if ($table === 'variable_set') {
            $this->pdo->exec('CREATE TABLE variable_set_v3 (variable_set_id VARCHAR(36) NOT NULL PRIMARY KEY, dataset_id VARCHAR(36) NOT NULL, source_ordinal INTEGER NOT NULL, set_name VARCHAR(255) NOT NULL, UNIQUE (dataset_id, source_ordinal), UNIQUE (dataset_id, set_name), FOREIGN KEY (dataset_id) REFERENCES dataset(dataset_id))');
            $this->pdo->exec('INSERT INTO variable_set_v3 (variable_set_id, dataset_id, source_ordinal, set_name) SELECT variable_set_id, dataset_id, source_ordinal, set_name FROM variable_set');
        } else {
            $this->pdo->exec('CREATE TABLE multiple_response_set_v3 (multiple_response_set_id VARCHAR(36) NOT NULL PRIMARY KEY, dataset_id VARCHAR(36) NOT NULL, source_ordinal INTEGER NOT NULL, set_name VARCHAR(255) NOT NULL, set_label TEXT NULL, set_kind VARCHAR(4) NOT NULL, counted_value_kind VARCHAR(16) NULL, counted_numeric_value DOUBLE NULL, counted_string_value TEXT NULL, category_label_behavior TEXT NULL, label_source TEXT NULL, UNIQUE (dataset_id, source_ordinal), UNIQUE (dataset_id, set_name), FOREIGN KEY (dataset_id) REFERENCES dataset(dataset_id))');
            $this->pdo->exec('INSERT INTO multiple_response_set_v3 (multiple_response_set_id, dataset_id, source_ordinal, set_name, set_label, set_kind, counted_value_kind, counted_numeric_value, counted_string_value, category_label_behavior, label_source) SELECT multiple_response_set_id, dataset_id, source_ordinal, set_name, set_label, set_kind, counted_value_kind, counted_numeric_value, counted_string_value, category_label_behavior, label_source FROM multiple_response_set');
        }
        $this->pdo->exec('DROP TABLE ' . $table);
        $this->pdo->exec('ALTER TABLE ' . $table . '_v3 RENAME TO ' . $table);
    }

    private function ensurePostgreSqlUniqueIndex(string $table, string $index): void
    {
        $statement = $this->statement("SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexdef LIKE 'CREATE UNIQUE INDEX%' AND REPLACE(indexdef, '\"', '') LIKE '%(dataset_id, source_ordinal)%' LIMIT 1");
        $statement->execute([$table]);
        if ($statement->fetchColumn() === false) {
            $this->pdo->exec('ALTER TABLE ' . $table . ' ADD CONSTRAINT ' . $index . ' UNIQUE (dataset_id, source_ordinal)');
        }
    }

    private function ensureMySqlUniqueIndex(string $table, string $index): void
    {
        $statement = $this->statement("SELECT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? GROUP BY index_name HAVING MIN(non_unique) = 0 AND GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') = 'dataset_id,source_ordinal' LIMIT 1");
        $statement->execute([$table]);
        if ($statement->fetchColumn() === false) {
            $this->pdo->exec('CREATE UNIQUE INDEX ' . $index . ' ON ' . $table . ' (dataset_id, source_ordinal)');
        }
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        try {
            $this->pdo->query('SELECT ' . $column . ' FROM ' . $table . ' WHERE 1 = 0');
        } catch (\PDOException) {
            $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    private function statement(string $sql): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The OpenStatSpec catalogue statement could not be prepared.');
        }
        return $statement;
    }

    public static function timestamp(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
