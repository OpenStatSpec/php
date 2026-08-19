<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Audit;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;
use PDOException;
use Throwable;

/** Explicitly upgrades the compact in-place transformation audit before apply. */
final readonly class TransformationAuditMigrator
{
    private const TABLE = 'transformation_apply';
    private const SQLITE_REPLACEMENT = 'transformation_apply_v04';

    public function __construct(private PDO $pdo) {}

    public function migrate(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Transformation audit migration cannot run inside an apply transaction.');
        }

        match ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => $this->migrateSqlite(),
            'pgsql' => $this->migratePostgreSql(),
            'mysql' => $this->migrateMySqlFamily(),
            default => throw new UnsupportedOperation(
                DiagnosticCode::UnsupportedSqlDriver,
                'The active SQL profile has no transformation audit migration.',
            ),
        };
    }

    private function migrateSqlite(): void
    {
        $this->pdo->beginTransaction();
        try {
            if (!$this->tableExists('sqlite')) {
                $this->pdo->exec($this->createTableSql(self::TABLE, 'sqlite'));
            } elseif (!$this->sqliteAcceptsVersion02()) {
                $this->assertReadableAuditTable();
                $this->pdo->exec($this->createTableSql(self::SQLITE_REPLACEMENT, 'sqlite'));
                $columns = implode(', ', self::columns());
                $this->pdo->exec(
                    'INSERT INTO ' . self::SQLITE_REPLACEMENT . ' (' . $columns . ') '
                    . 'SELECT ' . $columns . ' FROM ' . self::TABLE,
                );
                $this->pdo->exec('DROP TABLE ' . self::TABLE);
                $this->pdo->exec('ALTER TABLE ' . self::SQLITE_REPLACEMENT . ' RENAME TO ' . self::TABLE);
            } else {
                $this->assertReadableAuditTable();
            }

            $violations = $this->pdo->query('PRAGMA foreign_key_check');
            if ($violations === false || $violations->fetchColumn() !== false) {
                throw new PDOException('Transformation audit migration produced a foreign-key violation.');
            }
            $this->recordMigration();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function migratePostgreSql(): void
    {
        $this->pdo->beginTransaction();
        try {
            if (!$this->tableExists('pgsql')) {
                $this->pdo->exec($this->createTableSql(self::TABLE, 'pgsql'));
            } else {
                $this->assertReadableAuditTable();
                $checks = $this->postgreSqlContractChecks();
                if (!$this->checksAreCurrent($checks)) {
                    if ($checks === []) {
                        throw new PDOException('The existing transformation audit has no contract check to migrate.');
                    }
                    foreach (array_keys($checks) as $constraint) {
                        $this->pdo->exec('ALTER TABLE ' . self::TABLE . ' DROP CONSTRAINT ' . $this->quotePostgreSql($constraint));
                    }
                    $this->pdo->exec(
                        'ALTER TABLE ' . self::TABLE . ' ADD CONSTRAINT chk_transformation_apply_contract '
                        . $this->contractCheck(),
                    );
                }
            }
            $this->recordMigration();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function migrateMySqlFamily(): void
    {
        if (!$this->tableExists('mysql')) {
            $this->pdo->exec($this->createTableSql(self::TABLE, 'mysql'));
        } elseif (!$this->mySqlAcceptsVersion02()) {
            $this->assertReadableAuditTable();
            $this->migrateMySqlFamilyByRename();
        } else {
            $this->assertReadableAuditTable();
        }
        $this->recordMigration();
    }

    /**
     * Rebuild the audit table alongside its current name and use a single
     * multi-table RENAME to swap the new version into place atomically.
     *
     * MySQL/MariaDB implicitly commit DDL, so the in-place ALTER TABLE
     * DROP CHECK / ADD CONSTRAINT pair cannot be wrapped in a transaction
     * and leaves the audit table in a mixed state if the second statement
     * fails after the first succeeds. RENAME TABLE with two operands is
     * atomic: every concurrent reader sees exactly one of the old or the
     * new schema, never an inconsistent mixture.
     *
     * If any step before the atomic swap fails, the original table keeps
     * its old schema and a subsequent migrate() call retries from a clean
     * state.
     */
    private function migrateMySqlFamilyByRename(): void
    {
        $staging = self::TABLE . '_v04_pending';
        $archive = self::TABLE . '_pre_v04_archive';
        $columns = implode(', ', self::columns());

        $this->pdo->exec($this->createTableSql($staging, 'mysql'));
        $this->pdo->exec(
            'INSERT INTO ' . $staging . ' (' . $columns . ') '
            . 'SELECT ' . $columns . ' FROM ' . self::TABLE,
        );
        $this->pdo->exec(
            'RENAME TABLE ' . self::TABLE . ' TO ' . $archive . ', ' . $staging . ' TO ' . self::TABLE,
        );
        $this->pdo->exec('DROP TABLE ' . $archive);
    }

    private function createTableSql(string $table, string $driver): string
    {
        $applyUuid = $driver === 'pgsql' ? 'UUID' : 'VARCHAR(36)';
        $text = $driver === 'mysql' ? 'LONGTEXT' : 'TEXT';

        return 'CREATE TABLE ' . $table . ' ('
            . 'apply_id ' . $applyUuid . ' NOT NULL PRIMARY KEY, '
            . 'contract_id ' . $text . ' NOT NULL, '
            . 'database_profile ' . $text . ' NOT NULL, '
            . 'dataset_id VARCHAR(36) NOT NULL, '
            . 'physical_table_schema ' . $text . ' NULL, '
            . 'physical_table_name ' . $text . ' NOT NULL, '
            . 'source_hash CHAR(64) NOT NULL, '
            . 'plan_hash CHAR(64) NOT NULL, '
            . 'canonical_plan_json ' . $text . ' NOT NULL, '
            . 'actor ' . $text . ' NOT NULL, '
            . 'status ' . $text . ' NOT NULL, '
            . 'dolt_branch ' . $text . ' NULL, '
            . 'dolt_head_before ' . $text . ' NULL, '
            . 'dolt_head_after ' . $text . ' NULL, '
            . 'operation_count INTEGER NOT NULL, '
            . 'started_at TIMESTAMP NOT NULL, '
            . 'completed_at TIMESTAMP NOT NULL, '
            . 'CONSTRAINT fk_transformation_apply_dataset FOREIGN KEY (dataset_id) REFERENCES dataset(dataset_id), '
            . 'CONSTRAINT chk_transformation_apply_contract ' . $this->contractCheck() . ', '
            . "CONSTRAINT chk_transformation_apply_profile CHECK (database_profile IN ('sqlite', 'postgresql', 'mysql', 'mariadb', 'dolt')), "
            . "CONSTRAINT chk_transformation_apply_status CHECK (status IN ('succeeded', 'failed')), "
            . 'CONSTRAINT chk_transformation_apply_operation_count CHECK (operation_count > 0), '
            . 'CONSTRAINT chk_transformation_apply_dolt CHECK ('
            . "(database_profile <> 'dolt' AND dolt_branch IS NULL AND dolt_head_before IS NULL AND dolt_head_after IS NULL) "
            . "OR (database_profile = 'dolt' AND dolt_branch IS NOT NULL AND dolt_head_before IS NOT NULL "
            . "AND (status = 'failed' OR dolt_head_after = dolt_head_before))), "
            . $this->hashChecks($driver)
            . ')';
    }

    private function contractCheck(): string
    {
        return "CHECK (contract_id IN ('openstatspec-in-place-transformation-v0.1', 'openstatspec-in-place-transformation-v0.2'))";
    }

    private function hashChecks(string $driver): string
    {
        if ($driver === 'sqlite') {
            return "CONSTRAINT chk_transformation_apply_source_hash CHECK (length(source_hash) = 64 AND source_hash NOT GLOB '*[^0-9a-f]*'), "
                . "CONSTRAINT chk_transformation_apply_plan_hash CHECK (length(plan_hash) = 64 AND plan_hash NOT GLOB '*[^0-9a-f]*')";
        }
        if ($driver === 'pgsql') {
            return "CONSTRAINT chk_transformation_apply_source_hash CHECK (source_hash ~ '^[0-9a-f]{64}$'), "
                . "CONSTRAINT chk_transformation_apply_plan_hash CHECK (plan_hash ~ '^[0-9a-f]{64}$')";
        }
        // MySQL/MariaDB: CAST(... AS BINARY) is the binary collation cast; the BINARY
        // keyword as a type modifier is deprecated in MySQL 8.0.17+ in favor of
        // COLLATE ... USING BINARY, but the cast form remains supported on every
        // release line the adapter claims and reads more obviously to reviewers.
        return "CONSTRAINT chk_transformation_apply_source_hash CHECK (CHAR_LENGTH(source_hash) = 64 AND source_hash REGEXP '^[0-9a-f]{64}$' AND CAST(source_hash AS BINARY) = CAST(LOWER(source_hash) AS BINARY)), "
            . "CONSTRAINT chk_transformation_apply_plan_hash CHECK (CHAR_LENGTH(plan_hash) = 64 AND plan_hash REGEXP '^[0-9a-f]{64}$' AND CAST(plan_hash AS BINARY) = CAST(LOWER(plan_hash) AS BINARY))";
    }

    private function sqliteAcceptsVersion02(): bool
    {
        $statement = $this->pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '" . self::TABLE . "'");
        $sql = $statement === false ? false : $statement->fetchColumn();
        return is_string($sql)
            && str_contains($sql, 'openstatspec-in-place-transformation-v0.1')
            && str_contains($sql, 'openstatspec-in-place-transformation-v0.2');
    }

    /** @return array<string, string> */
    private function postgreSqlContractChecks(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT constraint_metadata.conname, pg_get_constraintdef(constraint_metadata.oid)
FROM pg_constraint constraint_metadata
JOIN pg_class relation ON relation.oid = constraint_metadata.conrelid
JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
WHERE namespace.nspname = current_schema()
  AND relation.relname = 'transformation_apply'
  AND constraint_metadata.contype = 'c'
  AND pg_get_constraintdef(constraint_metadata.oid) LIKE '%contract_id%'
SQL);
        if ($statement === false) {
            throw new PDOException('Could not inspect the PostgreSQL transformation audit checks.');
        }
        /** @var array<string, string> $checks */
        $checks = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
        return $checks;
    }

    /** @return array<string, string> */
    private function mySqlContractChecks(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT table_constraint.constraint_name, check_constraint.check_clause
FROM information_schema.table_constraints table_constraint
JOIN information_schema.check_constraints check_constraint
  ON check_constraint.constraint_schema = table_constraint.constraint_schema
 AND check_constraint.constraint_name = table_constraint.constraint_name
WHERE table_constraint.constraint_schema = DATABASE()
  AND table_constraint.table_name = 'transformation_apply'
  AND table_constraint.constraint_type = 'CHECK'
  AND check_constraint.check_clause LIKE '%contract_id%'
SQL);
        if ($statement === false) {
            throw new PDOException('Could not inspect the MySQL-family transformation audit checks.');
        }
        /** @var array<string, string> $checks */
        $checks = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
        return $checks;
    }

    private function mySqlAcceptsVersion02(): bool
    {
        foreach ($this->mySqlContractChecks() as $definition) {
            if (str_contains($definition, 'openstatspec-in-place-transformation-v0.1')
                && str_contains($definition, 'openstatspec-in-place-transformation-v0.2')
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, string> $checks */
    private function checksAreCurrent(array $checks): bool
    {
        if ($checks === []) {
            return false;
        }
        foreach ($checks as $definition) {
            if (!str_contains($definition, 'openstatspec-in-place-transformation-v0.1')
                || !str_contains($definition, 'openstatspec-in-place-transformation-v0.2')
            ) {
                return false;
            }
        }
        return true;
    }

    private function recordMigration(): void
    {
        $migration = match ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'mysql' => 'INSERT IGNORE INTO openstatspec_schema_migration (version, applied_at) VALUES (?, ?)',
            'pgsql', 'sqlite' => 'INSERT INTO openstatspec_schema_migration (version, applied_at) VALUES (?, ?) ON CONFLICT (version) DO NOTHING',
            default => throw new \LogicException('Unsupported audit migration driver.'),
        };
        $this->pdo->prepare($migration)->execute([4, gmdate('Y-m-d H:i:s')]);
    }

    private function assertReadableAuditTable(): void
    {
        $statement = $this->pdo->query('SELECT ' . implode(', ', self::columns()) . ' FROM ' . self::TABLE . ' WHERE 1 = 0');
        if ($statement === false) {
            throw new PDOException('The existing transformation audit table is not readable.');
        }
    }

    private function tableExists(string $driver): bool
    {
        $sql = match ($driver) {
            'sqlite' => "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?",
            'pgsql' => "SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' AND table_name = ?",
            'mysql' => "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name = ?",
            default => throw new \LogicException('Unsupported audit migration driver.'),
        };
        $statement = $this->pdo->prepare($sql);
        $statement->execute([self::TABLE]);
        return $statement->fetchColumn() !== false;
    }

    private function quotePostgreSql(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /** @return list<string> */
    private static function columns(): array
    {
        return [
            'apply_id', 'contract_id', 'database_profile', 'dataset_id', 'physical_table_schema',
            'physical_table_name', 'source_hash', 'plan_hash', 'canonical_plan_json', 'actor',
            'status', 'dolt_branch', 'dolt_head_before', 'dolt_head_after', 'operation_count',
            'started_at', 'completed_at',
        ];
    }
}
