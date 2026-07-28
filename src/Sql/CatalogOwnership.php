<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;
use PDOException;

/** Claims and verifies the exclusive database namespace used by the catalogue. */
final class CatalogOwnership
{
    private const IDENTITY_TABLE = 'catalog_identity';
    private const CONTRACT_ID = 'openstatspec-strict-wide-table-v1';
    private const MIGRATION_TABLE = 'openstatspec_schema_migration';
    private const SCHEMA_VERSION = 3;

    /** @return array<string, mixed> */
    public static function binding(PDO $pdo): array
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $statement = $pdo->query('PRAGMA database_list');
            $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (($row['name'] ?? null) === 'main') {
                    $file = is_string($row['file'] ?? null) && $row['file'] !== '' ? $row['file'] : ':memory:';
                    return self::bindingResult('sqlite_database', 'main', $file, self::namespaceInventoryVerified($pdo));
                }
            }
            return self::bindingResult('sqlite_database', 'main', null, false);
        }
        if ($driver === 'pgsql') {
            self::assertSinglePostgreSqlSchema($pdo);
            $schema = self::scalar($pdo, 'SELECT current_schema()');
            return self::bindingResult('schema', $schema, null, $schema !== null && self::namespaceInventoryVerified($pdo));
        }
        if ($driver === 'mysql') {
            $database = self::scalar($pdo, 'SELECT DATABASE()');
            return self::bindingResult('database', $database, null, $database !== null && self::namespaceInventoryVerified($pdo));
        }
        throw new UnsupportedOperation(DiagnosticCode::UnsupportedSqlDriver, 'The active connection has no catalogue namespace binding.');
    }

    public static function ensure(PDO $pdo): void
    {
        if (self::tableExists($pdo, self::IDENTITY_TABLE)) {
            self::validateIdentity($pdo);
            self::assertExclusiveNamespace($pdo);
            return;
        }

        $collisions = self::catalogCollisions($pdo);

        if (self::tableExists($pdo, self::MIGRATION_TABLE)) {
            $markerVersion = self::validateLegacyMarker($pdo);
            if (!self::isRecognizedMarkerCatalog($pdo, $collisions, $markerVersion)) {
                throw new UnsupportedOperation(
                    DiagnosticCode::CatalogNamespaceCollision,
                    'The migration marker shares its namespace with malformed or unrecognized catalogue objects: ' . implode(', ', $collisions) . '.',
                );
            }
            self::assertExclusiveNamespace($pdo);
            self::createIdentity($pdo, $markerVersion);
            return;
        }
        if (self::isRecognizedFullLegacyCatalog($pdo, $collisions)) {
            self::assertExclusiveNamespace($pdo);
            self::createIdentity($pdo, 1);
            $pdo->exec('CREATE TABLE ' . self::MIGRATION_TABLE . ' (version INTEGER NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL)');
            return;
        }
        if (self::isRecognizedJournalOnlyLegacyCatalog($pdo, $collisions)) {
            self::assertExclusiveNamespace($pdo);
            self::createIdentity($pdo, 1);
            $pdo->exec('CREATE TABLE ' . self::MIGRATION_TABLE . ' (version INTEGER NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL)');
            return;
        }
        $namespaceObjects = self::namespaceObjects($pdo);
        if ($namespaceObjects !== []) {
            throw new UnsupportedOperation(
                DiagnosticCode::CatalogNamespaceCollision,
                'A fresh OpenStatSpec claim requires an empty namespace; found: ' . implode(', ', self::objectLabels($namespaceObjects)) . '.',
            );
        }

        self::createIdentity($pdo, 1);
        $pdo->exec('CREATE TABLE ' . self::MIGRATION_TABLE . ' (version INTEGER NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL)');
    }

    public static function assertReadyForUse(PDO $pdo): void
    {
        if (self::tableExists($pdo, self::IDENTITY_TABLE)) {
            if (self::validateIdentity($pdo) < self::SCHEMA_VERSION) {
                throw self::migrationRequired();
            }
            self::assertExclusiveNamespace($pdo);
            return;
        }

        $collisions = self::catalogCollisions($pdo);
        if (self::tableExists($pdo, self::MIGRATION_TABLE)) {
            $markerVersion = self::validateLegacyMarker($pdo);
            if (self::isRecognizedMarkerCatalog($pdo, $collisions, $markerVersion)) {
                throw self::migrationRequired();
            }
            throw self::collision('The migration marker is not accompanied by a complete supported OpenStatSpec catalogue.');
        }
        if (self::isRecognizedFullLegacyCatalog($pdo, $collisions)
            || self::isRecognizedJournalOnlyLegacyCatalog($pdo, $collisions)
        ) {
            throw self::migrationRequired();
        }
        if ($collisions !== []) {
            throw self::collision('The active database namespace contains unowned catalogue objects: ' . implode(', ', $collisions) . '.');
        }

        self::ensure($pdo);
        throw self::migrationRequired();
    }

    public static function isFreshPending(PDO $pdo): bool
    {
        if (!self::tableExists($pdo, self::IDENTITY_TABLE)
            || !self::tableExists($pdo, self::MIGRATION_TABLE)
            || self::validateIdentity($pdo) !== 1
        ) {
            return false;
        }
        $objects = self::namespaceObjects($pdo);
        $tables = self::actualTableNames($objects);
        sort($tables);
        $expected = [self::IDENTITY_TABLE, self::MIGRATION_TABLE];
        sort($expected);
        if ($tables !== $expected || self::unexpectedNamespaceObjects($objects, $expected) !== []) {
            return false;
        }
        $statement = $pdo->query('SELECT COUNT(*) FROM ' . self::MIGRATION_TABLE);
        return $statement !== false && (int) $statement->fetchColumn() === 0;
    }

    /**
     * Record the current contract only after every catalogue migration and
     * backfill step has completed successfully.
     */
    public static function markCurrentVersion(PDO $pdo): void
    {
        self::validateIdentity($pdo);
        $statement = $pdo->prepare('UPDATE ' . self::IDENTITY_TABLE . ' SET schema_version = ? WHERE catalog_identity_key = 1');
        $statement->execute([self::SCHEMA_VERSION]);
    }

    /** @return array<string, mixed> */
    private static function bindingResult(string $mode, ?string $name, ?string $locator, bool $verified): array
    {
        return [
            'mode' => $mode,
            'name' => $name,
            'locator' => $locator,
            'exclusive_namespace_required' => true,
            'exclusive_namespace_verified' => $verified,
            'verification_status' => $verified ? 'verified_exclusive_runtime_inventory' : 'unverified',
            'identity_marker' => self::IDENTITY_TABLE,
            'logical_relation_mapping' => 'logical names resolve inside the active connection namespace',
        ];
    }

    private static function createIdentity(PDO $pdo, int $schemaVersion = self::SCHEMA_VERSION): void
    {
        $pdo->exec("CREATE TABLE " . self::IDENTITY_TABLE . " (catalog_identity_key INTEGER NOT NULL PRIMARY KEY, contract_id VARCHAR(128) NOT NULL UNIQUE, schema_version INTEGER NOT NULL, created_at TIMESTAMP NOT NULL, CHECK (catalog_identity_key = 1), CHECK (contract_id = 'openstatspec-strict-wide-table-v1'))");
        $statement = $pdo->prepare('INSERT INTO ' . self::IDENTITY_TABLE . ' (catalog_identity_key, contract_id, schema_version, created_at) VALUES (?, ?, ?, ?)');
        $statement->execute([1, self::CONTRACT_ID, $schemaVersion, gmdate('Y-m-d H:i:s')]);
    }

    private static function validateIdentity(PDO $pdo): int
    {
        try {
            $statement = $pdo->query('SELECT contract_id, schema_version FROM ' . self::IDENTITY_TABLE . ' WHERE catalog_identity_key = 1');
            $identity = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw self::collision('The existing catalog_identity object is not an OpenStatSpec ownership marker.', $exception);
        }
        $schemaVersion = is_array($identity) ? (int) ($identity['schema_version'] ?? 0) : 0;
        if (!is_array($identity)
            || ($identity['contract_id'] ?? null) !== self::CONTRACT_ID
            || $schemaVersion < 1
            || $schemaVersion > self::SCHEMA_VERSION
        ) {
            throw self::collision('The existing catalog_identity object has an unsupported owner, profile, or schema version.');
        }
        return $schemaVersion;
    }

    private static function validateLegacyMarker(PDO $pdo): int
    {
        try {
            $statement = $pdo->query('SELECT version, applied_at FROM ' . self::MIGRATION_TABLE . ' ORDER BY version');
            if ($statement === false) {
                throw self::collision('The existing openstatspec_schema_migration object is not readable.');
            }
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw self::collision('The existing openstatspec_schema_migration object is not a valid legacy ownership marker.', $exception);
        }

        $versions = [];
        foreach ($rows as $row) {
            $version = $row['version'] ?? null;
            if ((!is_int($version) && !is_string($version)) || !ctype_digit((string) $version)) {
                throw self::collision('The migration history contains a malformed version.');
            }
            $versions[] = (int) $version;
        }
        if ($versions === [] || $versions !== range(1, count($versions)) || end($versions) > self::SCHEMA_VERSION) {
            throw self::collision('The migration history must be the supported contiguous sequence 1..N.');
        }
        return end($versions);
    }

    /** @param list<string> $collisions */
    private static function isRecognizedMarkerCatalog(PDO $pdo, array $collisions, int $markerVersion): bool
    {
        if ($collisions === []) {
            return false;
        }

        $canonicalDefinition = self::canonicalCatalogDefinition();
        $legacyDefinition = self::legacyCatalogDefinition();
        $canonical = array_values(array_intersect($collisions, array_keys($canonicalDefinition)));
        $legacy = array_values(array_intersect($collisions, array_keys($legacyDefinition)));
        $journals = array_values(array_intersect($collisions, ['operation_catalog', 'fidelity_event_catalog']));

        $canonicalComplete = $canonical !== [] && self::matchesCompleteDefinition($pdo, $canonical, $canonicalDefinition);
        $legacyComplete = $legacy !== [] && self::matchesCompleteDefinition($pdo, $legacy, $legacyDefinition);
        $journalsComplete = $journals === [] || self::isRecognizedJournalOnlyLegacyCatalog($pdo, $journals);
        if (($canonical !== [] && !$canonicalComplete)
            || ($legacy !== [] && !$legacyComplete)
            || !$journalsComplete
        ) {
            return false;
        }
        return $markerVersion === self::SCHEMA_VERSION
            ? $canonicalComplete
            : ($canonicalComplete || $legacyComplete);
    }

    /** @param list<string> $collisions */
    private static function isRecognizedFullLegacyCatalog(PDO $pdo, array $collisions): bool
    {
        $definition = self::legacyCatalogDefinition();
        $required = array_keys($definition);
        $allowed = [...$required, 'operation_catalog', 'fidelity_event_catalog'];
        if (array_diff($required, $collisions) !== [] || array_diff($collisions, $allowed) !== []) {
            return false;
        }

        if (!self::matchesCompleteDefinition($pdo, array_values(array_intersect($collisions, $required)), $definition)) {
            return false;
        }

        $journals = array_values(array_intersect($collisions, ['operation_catalog', 'fidelity_event_catalog']));
        return $journals === [] || self::isRecognizedJournalOnlyLegacyCatalog($pdo, $journals);
    }

    /** @param list<string> $collisions */
    private static function isRecognizedJournalOnlyLegacyCatalog(PDO $pdo, array $collisions): bool
    {
        if ($collisions === [] || array_diff($collisions, ['operation_catalog', 'fidelity_event_catalog']) !== []) {
            return false;
        }
        return (!in_array('operation_catalog', $collisions, true) || self::canSelectColumns($pdo, 'operation_catalog', ['operation_id', 'direction', 'status', 'dataset_name', 'target_path', 'allow_loss', 'failure_code', 'failure_message']))
            && (!in_array('fidelity_event_catalog', $collisions, true) || self::canSelectColumns($pdo, 'fidelity_event_catalog', ['operation_id', 'ordinal', 'dataset_name', 'severity', 'code', 'message', 'details']));
    }

    /**
     * @param list<string>                $tables
     * @param array<string, list<string>> $definition
     */
    private static function matchesCompleteDefinition(PDO $pdo, array $tables, array $definition): bool
    {
        if (array_diff(array_keys($definition), $tables) !== [] || array_diff($tables, array_keys($definition)) !== []) {
            return false;
        }
        foreach ($definition as $table => $columns) {
            if (!self::canSelectColumns($pdo, $table, $columns)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $columns */
    private static function canSelectColumns(PDO $pdo, string $table, array $columns): bool
    {
        try {
            return $pdo->query('SELECT ' . implode(', ', $columns) . ' FROM ' . $table . ' WHERE 1 = 0') !== false;
        } catch (PDOException) {
            return false;
        }
    }

    /** @return array<string, list<string>> */
    private static function canonicalCatalogDefinition(): array
    {
        return [
            'dataset' => ['dataset_id', 'spec_version', 'source_format', 'physical_table_schema', 'physical_table_name', 'dataset_name', 'dataset_label', 'source_encoding', 'source_hash', 'source_case_count', 'imported_at'],
            'variable' => ['variable_id', 'dataset_id', 'source_ordinal', 'source_name', 'physical_name', 'storage_kind', 'declared_string_width', 'variable_label', 'print_format_family', 'print_format_width', 'print_format_decimals', 'write_format_family', 'write_format_width', 'write_format_decimals', 'measurement_level', 'variable_role', 'display_width', 'display_alignment'],
            'dataset_weight_variable' => ['dataset_id', 'variable_id'],
            'value_label_set' => ['value_label_set_id', 'dataset_id', 'name'],
            'value_label' => ['value_label_id', 'value_label_set_id', 'ordinal', 'code_kind', 'numeric_code', 'string_code', 'label'],
            'variable_value_label_set' => ['variable_id', 'value_label_set_id'],
            'missing_rule' => ['missing_rule_id', 'variable_id', 'ordinal', 'rule_kind', 'code_kind', 'numeric_value', 'string_value', 'numeric_lower', 'numeric_upper', 'lower_special', 'upper_special'],
            'dataset_attribute' => ['dataset_attribute_id', 'dataset_id', 'attribute_name', 'array_ordinal', 'attribute_value'],
            'variable_attribute' => ['variable_attribute_id', 'variable_id', 'attribute_name', 'array_ordinal', 'attribute_value'],
            'document' => ['document_id', 'dataset_id', 'source_ordinal', 'document_text'],
            'variable_set' => ['variable_set_id', 'dataset_id', 'source_ordinal', 'set_name'],
            'variable_set_member' => ['variable_set_id', 'variable_id', 'source_ordinal'],
            'multiple_response_set' => ['multiple_response_set_id', 'dataset_id', 'source_ordinal', 'set_name', 'set_label', 'set_kind', 'counted_value_kind', 'counted_numeric_value', 'counted_string_value', 'category_label_behavior', 'label_source'],
            'multiple_response_member' => ['multiple_response_set_id', 'variable_id', 'source_ordinal'],
            'operation' => ['operation_id', 'operation_kind', 'status', 'source_format', 'started_at', 'completed_at'],
            'fidelity_event' => ['fidelity_event_id', 'operation_id', 'dataset_id', 'direction', 'severity', 'event_code', 'source_item', 'detail_json', 'created_at'],
        ];
    }

    /** @return array<string, list<string>> */
    private static function legacyCatalogDefinition(): array
    {
        return [
            'datasets' => ['dataset_name', 'table_name'],
            'variables' => ['dataset_name', 'ordinal', 'source_name', 'column_name', 'storage_kind', 'source_width', 'format_family', 'format_width', 'format_decimals', 'write_format_family', 'write_format_width', 'write_format_decimals', 'label'],
            'dataset_weight_variables' => ['dataset_name', 'variable_ordinal'],
            'dataset_metadata' => ['dataset_name', 'meta_key', 'meta_value'],
            'file_technical_metadata' => ['dataset_name', 'source_format', 'record_type', 'source_version', 'provenance', 'encoding', 'product_name', 'raw_creation_date', 'raw_creation_time', 'case_count', 'nominal_case_size', 'layout_code', 'compression', 'compression_bias', 'machine_code', 'floating_point_representation', 'endianness', 'character_code'],
            'documents' => ['dataset_name', 'ordinal', 'text'],
            'value_labels' => ['dataset_name', 'variable_ordinal', 'ordinal', 'value_kind', 'numeric_value', 'text_value', 'label'],
            'missing_rules' => ['dataset_name', 'variable_ordinal', 'missing_format'],
            'missing_rule_values' => ['dataset_name', 'variable_ordinal', 'ordinal', 'value_kind', 'numeric_value', 'text_value'],
            'variable_display_metadata' => ['dataset_name', 'variable_ordinal', 'measurement_level', 'display_width', 'alignment'],
            'variable_roles' => ['dataset_name', 'variable_ordinal', 'role'],
            'file_attributes' => ['dataset_name', 'attribute_name', 'ordinal', 'value'],
            'variable_attributes' => ['dataset_name', 'variable_ordinal', 'attribute_name', 'ordinal', 'value'],
            'variable_sets' => ['dataset_name', 'set_ordinal', 'name'],
            'variable_set_members' => ['dataset_name', 'set_ordinal', 'member_ordinal', 'variable_ordinal'],
            'multiple_response_sets' => ['dataset_name', 'set_ordinal', 'name', 'set_type', 'label', 'counted_value_kind', 'counted_numeric_value', 'counted_text_value', 'category_labels', 'label_source'],
            'multiple_response_set_members' => ['dataset_name', 'set_ordinal', 'member_ordinal', 'variable_ordinal'],
        ];
    }

    private static function namespaceInventoryVerified(PDO $pdo): bool
    {
        try {
            if (self::tableExists($pdo, self::IDENTITY_TABLE)) {
                self::validateIdentity($pdo);
                self::assertExclusiveNamespace($pdo);
                return true;
            }
            return self::namespaceObjects($pdo) === [];
        } catch (UnsupportedOperation|PDOException) {
            return false;
        }
    }

    private static function assertExclusiveNamespace(PDO $pdo): void
    {
        $catalogTables = self::catalogTables();
        $registeredTables = self::registeredPhysicalTables($pdo);
        $allowed = [
            self::IDENTITY_TABLE,
            self::MIGRATION_TABLE,
            ...$catalogTables,
            ...$registeredTables,
        ];
        $unexpected = self::unexpectedNamespaceObjects(self::namespaceObjects($pdo), array_values(array_unique($allowed)), $registeredTables);
        if ($unexpected !== []) {
            throw self::collision('The OpenStatSpec namespace contains unrelated schema objects: ' . implode(', ', self::objectLabels($unexpected)) . '.');
        }
    }

    /** @return list<string> */
    private static function registeredPhysicalTables(PDO $pdo): array
    {
        $tables = [];
        foreach ([
            ['table' => 'dataset', 'column' => 'physical_table_name'],
            ['table' => 'datasets', 'column' => 'table_name'],
        ] as $source) {
            if (!self::tableExists($pdo, $source['table'])) {
                continue;
            }
            try {
                $statement = $pdo->query('SELECT ' . $source['column'] . ' FROM ' . $source['table']);
                if ($statement === false) {
                    throw self::collision('A physical-table registry is not readable.');
                }
                foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
                    if (is_string($table) && $table !== '') {
                        $tables[] = $table;
                    }
                }
            } catch (PDOException $exception) {
                throw self::collision('A physical-table registry is malformed.', $exception);
            }
        }
        return array_values(array_unique($tables));
    }

    /**
     * @return list<array{name: string, kind: string, parent_name: string|null, constraint_owned: bool}>
     */
    private static function namespaceObjects(PDO $pdo): array
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $statement = match ($driver) {
            'sqlite' => $pdo->query("SELECT name, type AS kind, tbl_name AS parent_name, 0 AS constraint_owned FROM sqlite_master WHERE type IN ('table', 'view', 'index', 'trigger') AND name NOT LIKE 'sqlite_%' ORDER BY name"),
            'pgsql' => self::postgreSqlNamespaceObjects($pdo),
            'mysql' => self::mySqlNamespaceObjects($pdo),
            default => throw new UnsupportedOperation(DiagnosticCode::UnsupportedSqlDriver, 'The active connection has no namespace inventory profile.'),
        };
        if ($statement === false) {
            throw self::collision('The active database namespace could not be inventoried.');
        }
        $objects = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = $row['name'] ?? null;
            $kind = $row['kind'] ?? null;
            $parent = $row['parent_name'] ?? null;
            $constraintOwned = $row['constraint_owned'] ?? false;
            if (!is_string($name) || $name === '' || !is_string($kind) || $kind === '') {
                throw self::collision('The active database namespace returned a malformed inventory row.');
            }
            $objects[] = [
                'name' => $name,
                'kind' => $kind,
                'parent_name' => is_string($parent) && $parent !== '' ? $parent : null,
                'constraint_owned' => $constraintOwned === true || $constraintOwned === 1 || $constraintOwned === '1' || $constraintOwned === 't',
            ];
        }
        return $objects;
    }

    private static function postgreSqlNamespaceObjects(PDO $pdo): \PDOStatement|false
    {
        self::assertSinglePostgreSqlSchema($pdo);
        return $pdo->query(<<<'SQL'
            SELECT name, kind, parent_name, constraint_owned
            FROM (
                SELECT
                    relation.relname AS name,
                    CASE relation.relkind
                        WHEN 'r' THEN 'table'
                        WHEN 'p' THEN 'partitioned_table'
                        WHEN 'v' THEN 'view'
                        WHEN 'm' THEN 'materialized_view'
                        WHEN 'S' THEN 'sequence'
                        WHEN 'f' THEN 'foreign_table'
                        WHEN 'i' THEN 'index'
                        WHEN 'I' THEN 'partitioned_index'
                    END AS kind,
                    parent.relname AS parent_name,
                    EXISTS (SELECT 1 FROM pg_constraint constraint_metadata WHERE constraint_metadata.conindid = relation.oid) AS constraint_owned
                FROM pg_class relation
                JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
                LEFT JOIN pg_index index_metadata ON index_metadata.indexrelid = relation.oid
                LEFT JOIN pg_class parent ON parent.oid = index_metadata.indrelid
                WHERE namespace.nspname = current_schema()
                  AND relation.relkind IN ('r', 'p', 'v', 'm', 'S', 'f', 'i', 'I')

                UNION ALL

                SELECT
                    user_type.typname AS name,
                    CASE
                        WHEN user_type.typtype = 'e' THEN 'enum_type'
                        WHEN user_type.typtype = 'd' THEN 'domain_type'
                        WHEN user_type.typtype = 'b' THEN 'base_type'
                        WHEN user_type.typtype = 'r' THEN 'range_type'
                        WHEN user_type.typtype = 'm' THEN 'multirange_type'
                        WHEN type_relation.relkind IN ('r', 'p') THEN 'relation_row_type'
                        WHEN type_relation.relkind IN ('v', 'm') THEN 'view_row_type'
                        ELSE 'composite_type'
                    END AS kind,
                    type_relation.relname AS parent_name,
                    FALSE AS constraint_owned
                FROM pg_type user_type
                JOIN pg_namespace type_namespace ON type_namespace.oid = user_type.typnamespace
                LEFT JOIN pg_class type_relation ON type_relation.oid = user_type.typrelid
                WHERE type_namespace.nspname = current_schema()
                  AND user_type.typtype IN ('b', 'c', 'd', 'e', 'r', 'm')
                  AND user_type.typelem = 0

                UNION ALL

                SELECT
                    routine.proname AS name,
                    CASE routine.prokind WHEN 'p' THEN 'procedure' WHEN 'a' THEN 'aggregate' WHEN 'w' THEN 'window_function' ELSE 'function' END AS kind,
                    NULL AS parent_name,
                    FALSE AS constraint_owned
                FROM pg_proc routine
                JOIN pg_namespace routine_namespace ON routine_namespace.oid = routine.pronamespace
                WHERE routine_namespace.nspname = current_schema()

                UNION ALL

                SELECT trigger.tgname, 'trigger', trigger_relation.relname, FALSE
                FROM pg_trigger trigger
                JOIN pg_class trigger_relation ON trigger_relation.oid = trigger.tgrelid
                JOIN pg_namespace trigger_namespace ON trigger_namespace.oid = trigger_relation.relnamespace
                WHERE trigger_namespace.nspname = current_schema() AND NOT trigger.tgisinternal

                UNION ALL

                SELECT rewrite_rule.rulename, 'rewrite_rule', rule_relation.relname, FALSE
                FROM pg_rewrite rewrite_rule
                JOIN pg_class rule_relation ON rule_relation.oid = rewrite_rule.ev_class
                JOIN pg_namespace rule_namespace ON rule_namespace.oid = rule_relation.relnamespace
                WHERE rule_namespace.nspname = current_schema() AND rewrite_rule.rulename <> '_RETURN'

                UNION ALL

                SELECT policy.polname, 'row_security_policy', policy_relation.relname, FALSE
                FROM pg_policy policy
                JOIN pg_class policy_relation ON policy_relation.oid = policy.polrelid
                JOIN pg_namespace policy_namespace ON policy_namespace.oid = policy_relation.relnamespace
                WHERE policy_namespace.nspname = current_schema()
            ) namespace_object
            ORDER BY kind, name
            SQL);
    }

    private static function mySqlNamespaceObjects(PDO $pdo): \PDOStatement|false
    {
        return $pdo->query(<<<'SQL'
            SELECT name, kind, parent_name, constraint_owned
            FROM (
                SELECT
                    table_name AS name,
                    CASE table_type WHEN 'BASE TABLE' THEN 'table' WHEN 'VIEW' THEN 'view' WHEN 'SEQUENCE' THEN 'sequence' ELSE LOWER(REPLACE(table_type, ' ', '_')) END AS kind,
                    NULL AS parent_name,
                    0 AS constraint_owned
                FROM information_schema.tables
                WHERE table_schema = DATABASE()

                UNION ALL

                SELECT trigger_name, 'trigger', event_object_table, 0
                FROM information_schema.triggers
                WHERE trigger_schema = DATABASE()

                UNION ALL

                SELECT routine_name, LOWER(routine_type), NULL, 0
                FROM information_schema.routines
                WHERE routine_schema = DATABASE()

                UNION ALL

                SELECT event_name, 'event', NULL, 0
                FROM information_schema.events
                WHERE event_schema = DATABASE()

                UNION ALL

                SELECT
                    statistics.index_name AS name,
                    'index' AS kind,
                    statistics.table_name AS parent_name,
                    MAX(
                        CASE WHEN EXISTS (
                            SELECT 1
                            FROM information_schema.table_constraints table_constraint
                            WHERE table_constraint.constraint_schema = DATABASE()
                              AND table_constraint.table_name = statistics.table_name
                              AND table_constraint.constraint_name = statistics.index_name
                              AND table_constraint.constraint_type IN ('PRIMARY KEY', 'UNIQUE')
                        ) THEN 1 ELSE 0 END
                    ) AS constraint_owned
                FROM information_schema.statistics statistics
                WHERE statistics.table_schema = DATABASE()
                  AND statistics.non_unique = 0
                GROUP BY statistics.table_name, statistics.index_name
            ) namespace_object
            ORDER BY kind, name
            SQL);
    }

    /**
     * @param list<array{name: string, kind: string, parent_name: string|null, constraint_owned: bool}> $objects
     * @param list<string>                                                                               $allowedTables
     * @param list<string>                                                                               $registeredTables
     * @return list<array{name: string, kind: string, parent_name: string|null, constraint_owned: bool}>
     */
    private static function unexpectedNamespaceObjects(array $objects, array $allowedTables, array $registeredTables = []): array
    {
        $allowed = array_fill_keys($allowedTables, true);
        $registered = array_fill_keys($registeredTables, true);
        return array_values(array_filter(
            $objects,
            static function (array $object) use ($allowed, $registered): bool {
                if (self::isActualTableKind($object['kind'])) {
                    return !isset($allowed[$object['name']]);
                }
                if ($object['kind'] === 'relation_row_type') {
                    return $object['parent_name'] === null || !isset($allowed[$object['parent_name']]);
                }
                if (in_array($object['kind'], ['index', 'partitioned_index'], true)) {
                    if ($object['parent_name'] === null || !isset($allowed[$object['parent_name']])) {
                        return true;
                    }
                    return !isset($registered[$object['parent_name']]) && !$object['constraint_owned'];
                }
                return true;
            },
        ));
    }

    /**
     * @param list<array{name: string, kind: string, parent_name: string|null, constraint_owned: bool}> $objects
     * @return list<string>
     */
    private static function actualTableNames(array $objects): array
    {
        return array_values(array_map(
            static fn(array $object): string => $object['name'],
            array_filter($objects, static fn(array $object): bool => self::isActualTableKind($object['kind'])),
        ));
    }

    private static function isActualTableKind(string $kind): bool
    {
        return in_array($kind, ['table', 'partitioned_table'], true);
    }

    /**
     * @param list<array{name: string, kind: string, parent_name: string|null, constraint_owned: bool}> $objects
     * @return list<string>
     */
    private static function objectLabels(array $objects): array
    {
        return array_map(
            static fn(array $object): string => $object['kind'] . ' ' . $object['name'],
            $objects,
        );
    }

    private static function assertSinglePostgreSqlSchema(PDO $pdo): void
    {
        $statement = $pdo->query('SELECT COUNT(*) FROM unnest(current_schemas(false))');
        $count = $statement === false ? 0 : (int) $statement->fetchColumn();
        if ($count !== 1) {
            throw self::collision('PostgreSQL current_schemas(false) must resolve to exactly one application schema.');
        }
    }

    /** @return list<string> */
    private static function catalogCollisions(PDO $pdo): array
    {
        return array_values(array_filter(
            self::catalogTables(),
            static fn(string $table): bool => self::tableExists($pdo, $table),
        ));
    }

    private static function migrationRequired(): UnsupportedOperation
    {
        return new UnsupportedOperation(
            DiagnosticCode::CatalogMigrationRequired,
            'The OpenStatSpec catalogue must be upgraded explicitly with migrateCatalog() before import or export.',
        );
    }

    private static function collision(string $message, ?\Throwable $previous = null): UnsupportedOperation
    {
        if ($previous !== null) {
            $message .= ' ' . $previous->getMessage();
        }
        return new UnsupportedOperation(DiagnosticCode::CatalogNamespaceCollision, $message);
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = match ($driver) {
            'sqlite' => "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?",
            'pgsql' => "SELECT 1 FROM pg_class relation JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace WHERE namespace.nspname = current_schema() AND relation.relkind IN ('r', 'p') AND relation.relname = ?",
            'mysql' => "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name = ?",
            default => throw new UnsupportedOperation(DiagnosticCode::UnsupportedSqlDriver, 'The active connection has no catalogue ownership profile.'),
        };
        $statement = $pdo->prepare($sql);
        $statement->execute([$table]);
        return $statement->fetchColumn() !== false;
    }

    private static function scalar(PDO $pdo, string $sql): ?string
    {
        $statement = $pdo->query($sql);
        $value = $statement === false ? false : $statement->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private static function catalogTables(): array
    {
        return [
            'dataset', 'variable', 'dataset_weight_variable', 'value_label_set', 'value_label',
            'variable_value_label_set', 'missing_rule', 'dataset_attribute', 'variable_attribute',
            'document', 'variable_set', 'variable_set_member', 'multiple_response_set',
            'multiple_response_member', 'operation', 'fidelity_event', 'datasets', 'variables',
            'dataset_weight_variables', 'dataset_metadata', 'file_technical_metadata', 'documents',
            'value_labels', 'missing_rules', 'missing_rule_values', 'variable_display_metadata',
            'variable_roles', 'file_attributes', 'variable_attributes', 'variable_sets',
            'variable_set_members', 'multiple_response_sets', 'multiple_response_set_members',
            'operation_catalog', 'fidelity_event_catalog',
        ];
    }
}
