<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

use OpenStatSpec\Spss\SpssAdapter;
use OpenStatSpec\Sql\MySqlIndexIntrospection;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ServerCatalogMigrationTest extends TestCase
{
    public function testLegacyV3ConstraintsAndSourceOrderMigrateOnConfiguredServers(): void
    {
        $connections = $this->connections();
        if ($connections === []) {
            self::markTestSkipped('No server PDO integration profile is configured.');
        }

        foreach ($connections as $name => $pdo) {
            $adapter = new SpssAdapter($pdo);
            $adapter->migrateCatalog();
            $token = bin2hex(random_bytes(6));
            $datasetId = 'migration-' . $token;
            $datasetName = 'migration ' . $name . ' ' . $token;
            try {
                $this->makeV3Legacy($pdo);
                $pdo->prepare('INSERT INTO dataset (dataset_id, spec_version, source_format, physical_table_name, dataset_name, source_case_count, imported_at) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$datasetId, '1.0', 'sav', 'unused_' . $token, $datasetName, 0, '2026-07-28 00:00:00']);
                $pdo->prepare('INSERT INTO variable_sets (dataset_name, set_ordinal, name) VALUES (?, ?, ?)')->execute([$datasetName, 2, 'Legacy variable set']);
                $pdo->prepare('INSERT INTO multiple_response_sets (dataset_name, set_ordinal, name, set_type, category_labels, label_source) VALUES (?, ?, ?, ?, ?, ?)')->execute([$datasetName, 1, '$Legacy MR', 'dichotomy', 'counted_values', 'set_label']);
                $pdo->prepare('INSERT INTO variable_set (variable_set_id, dataset_id, source_ordinal, set_name) VALUES (?, ?, NULL, ?)')->execute(['vs-' . $token, $datasetId, 'Legacy variable set']);
                $pdo->prepare('INSERT INTO multiple_response_set (multiple_response_set_id, dataset_id, source_ordinal, set_name, set_kind, category_label_behavior) VALUES (?, ?, NULL, ?, ?, ?)')->execute(['mr-' . $token, $datasetId, '$Legacy MR', 'MD', 'counted_values']);
                $legacyApplyId = $this->makeVersion01Audit($pdo, $name, $datasetId, 'unused_' . $token);

                $adapter->migrateCatalog();
                self::assertSame(2, (int) $this->scalar($pdo, 'SELECT source_ordinal FROM variable_set WHERE dataset_id = ?', [$datasetId]), $name . ': variable-set source order');
                self::assertSame(1, (int) $this->scalar($pdo, 'SELECT source_ordinal FROM multiple_response_set WHERE dataset_id = ?', [$datasetId]), $name . ': MR-set source order');
                self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM transformation_apply WHERE apply_id = ?', [$legacyApplyId]), $name . ': existing 0.1 audit row');
                $this->assertAuditContracts($pdo, $name, $datasetId, 'unused_' . $token);
                $this->assertNotNullAndUnique($pdo, $name);
                foreach ([
                    ['INSERT INTO variable_set (variable_set_id, dataset_id, source_ordinal, set_name) VALUES (?, ?, ?, ?)', ['vs-duplicate-' . $token, $datasetId, 2, 'Duplicate variable set']],
                    ['INSERT INTO multiple_response_set (multiple_response_set_id, dataset_id, source_ordinal, set_name, set_kind) VALUES (?, ?, ?, ?, ?)', ['mr-duplicate-' . $token, $datasetId, 1, '$Duplicate MR', 'MC']],
                ] as [$sql, $parameters]) {
                    try {
                        $pdo->prepare($sql)->execute($parameters);
                        self::fail($name . ': duplicate set ordinal was accepted');
                    } catch (PDOException) {
                        // The database-enforced uniqueness guarantee is the assertion.
                    }
                }
                $adapter->migrateCatalog();
                self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM openstatspec_schema_migration WHERE version = 3', []), $name . ': migration marker must be idempotent');
                self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM openstatspec_schema_migration WHERE version = 4', []), $name . ': audit migration marker must be idempotent');
            } finally {
                $pdo->prepare('DELETE FROM transformation_apply WHERE dataset_id = ?')->execute([$datasetId]);
                $pdo->prepare('DELETE FROM multiple_response_set WHERE dataset_id = ?')->execute([$datasetId]);
                $pdo->prepare('DELETE FROM variable_set WHERE dataset_id = ?')->execute([$datasetId]);
                $pdo->prepare('DELETE FROM multiple_response_sets WHERE dataset_name = ?')->execute([$datasetName]);
                $pdo->prepare('DELETE FROM variable_sets WHERE dataset_name = ?')->execute([$datasetName]);
                $pdo->prepare('DELETE FROM dataset WHERE dataset_id = ?')->execute([$datasetId]);
            }
        }
    }

    private function makeVersion01Audit(PDO $pdo, string $profile, string $datasetId, string $table): string
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec('ALTER TABLE transformation_apply DROP CONSTRAINT chk_transformation_apply_contract');
        } else {
            $drop = $profile === 'mariadb' ? 'DROP CONSTRAINT' : 'DROP CHECK';
            $pdo->exec('ALTER TABLE transformation_apply ' . $drop . ' chk_transformation_apply_contract');
        }
        $pdo->exec("ALTER TABLE transformation_apply ADD CONSTRAINT chk_transformation_apply_contract CHECK (contract_id = 'openstatspec-in-place-transformation-v0.1')");
        $pdo->exec('DELETE FROM openstatspec_schema_migration WHERE version = 4');

        $applyId = $this->uuidV4();
        $dolt = $profile === 'dolt';
        $pdo->prepare('INSERT INTO transformation_apply VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $applyId,
            'openstatspec-in-place-transformation-v0.1',
            $profile,
            $datasetId,
            null,
            $table,
            str_repeat('c', 64),
            str_repeat('d', 64),
            '{}',
            'legacy-migration-test',
            'succeeded',
            $dolt ? 'migration-test' : null,
            $dolt ? 'migration-head' : null,
            $dolt ? 'migration-head' : null,
            1,
            '2026-08-16 00:00:00',
            '2026-08-16 00:00:01',
        ]);
        return $applyId;
    }

    private function assertAuditContracts(PDO $pdo, string $profile, string $datasetId, string $table): void
    {
        $insert = $pdo->prepare(<<<'SQL'
INSERT INTO transformation_apply (
    apply_id, contract_id, database_profile, dataset_id, physical_table_schema,
    physical_table_name, source_hash, plan_hash, canonical_plan_json, actor,
    status, dolt_branch, dolt_head_before, dolt_head_after, operation_count,
    started_at, completed_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
        foreach (['openstatspec-in-place-transformation-v0.1', 'openstatspec-in-place-transformation-v0.2'] as $contract) {
            $dolt = $profile === 'dolt';
            $insert->execute([
                $this->uuidV4(),
                $contract,
                $profile,
                $datasetId,
                null,
                $table,
                str_repeat('a', 64),
                str_repeat('b', 64),
                '{}',
                'migration-test',
                'succeeded',
                $dolt ? 'migration-test' : null,
                $dolt ? 'migration-head' : null,
                $dolt ? 'migration-head' : null,
                1,
                '2026-08-17 00:00:00',
                '2026-08-17 00:00:01',
            ]);
        }
        self::assertSame(
            2,
            (int) $this->scalar($pdo, "SELECT COUNT(*) FROM transformation_apply WHERE dataset_id = ? AND actor = 'migration-test'", [$datasetId]),
            $profile . ': both official audit contracts',
        );
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function makeV3Legacy(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach (['variable_set', 'multiple_response_set'] as $table) {
            $this->dropSetOrdinalUniqueKey($pdo, $table);
        }
        if ($driver === 'pgsql') {
            $pdo->exec('ALTER TABLE variable_set ALTER COLUMN source_ordinal DROP NOT NULL');
            $pdo->exec('ALTER TABLE multiple_response_set ALTER COLUMN source_ordinal DROP NOT NULL');
            $pdo->exec('ALTER TABLE multiple_response_set DROP COLUMN counted_value_kind');
            $pdo->exec('ALTER TABLE multiple_response_set DROP COLUMN counted_string_value');
            $pdo->exec('ALTER TABLE multiple_response_set DROP COLUMN label_source');
        } else {
            $pdo->exec('ALTER TABLE variable_set MODIFY source_ordinal INTEGER NULL');
            $pdo->exec('ALTER TABLE multiple_response_set MODIFY source_ordinal INTEGER NULL');
            $pdo->exec('ALTER TABLE multiple_response_set DROP COLUMN counted_value_kind');
            $pdo->exec('ALTER TABLE multiple_response_set DROP COLUMN counted_string_value');
            $pdo->exec('ALTER TABLE multiple_response_set DROP COLUMN label_source');
        }
        $pdo->exec('DELETE FROM openstatspec_schema_migration WHERE version = 3');
    }

    private function dropSetOrdinalUniqueKey(PDO $pdo, string $table): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $statement = $pdo->prepare(<<<'SQL'
SELECT tc.constraint_name
FROM information_schema.table_constraints tc
JOIN information_schema.key_column_usage kcu
  ON kcu.constraint_schema = tc.constraint_schema
 AND kcu.constraint_name = tc.constraint_name
 AND kcu.table_name = tc.table_name
WHERE tc.constraint_schema = current_schema()
  AND tc.table_name = ?
  AND tc.constraint_type = 'UNIQUE'
GROUP BY tc.constraint_name
HAVING string_agg(kcu.column_name, ',' ORDER BY kcu.ordinal_position) = 'dataset_id,source_ordinal'
SQL);
            $statement->execute([$table]);
            $constraint = $statement->fetchColumn();
            if (is_string($constraint)) {
                $pdo->exec('ALTER TABLE "' . str_replace('"', '""', $table) . '" DROP CONSTRAINT "' . str_replace('"', '""', $constraint) . '"');
            }
            return;
        }

        $statement = $pdo->prepare('SELECT index_name, non_unique, column_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? ORDER BY index_name, seq_in_index');
        $statement->execute([$table]);
        $uniqueIndexes = MySqlIndexIntrospection::uniqueColumnLists($statement->fetchAll(PDO::FETCH_ASSOC));
        $index = array_search(['dataset_id', 'source_ordinal'], $uniqueIndexes, true);
        if (is_string($index)) {
            $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` DROP INDEX `' . str_replace('`', '``', $index) . '`');
        }
    }

    private function assertNotNullAndUnique(PDO $pdo, string $name): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            foreach (['variable_set', 'multiple_response_set'] as $table) {
                self::assertSame('NO', $this->scalar($pdo, 'SELECT is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?', [$table, 'source_ordinal']), $name . ': NOT NULL');
            }
            foreach (['counted_value_kind', 'counted_string_value', 'label_source'] as $column) {
                self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?', ['multiple_response_set', $column]), $name . ': restored ' . $column);
            }
            return;
        }
        foreach (['variable_set', 'multiple_response_set'] as $table) {
            self::assertSame('NO', $this->scalar($pdo, 'SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, 'source_ordinal']), $name . ': NOT NULL');
        }
        foreach (['counted_value_kind', 'counted_string_value', 'label_source'] as $column) {
            self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', ['multiple_response_set', $column]), $name . ': restored ' . $column);
        }
    }

    /** @return array<string, PDO> */
    private function connections(): array
    {
        $connections = [];
        foreach (['mysql' => 'OPENSTATSPEC_MYSQL', 'mariadb' => 'OPENSTATSPEC_MARIADB', 'dolt' => 'OPENSTATSPEC_DOLT', 'postgresql' => 'OPENSTATSPEC_PG'] as $name => $prefix) {
            $dsn = getenv($prefix . '_DSN');
            $driver = $name === 'postgresql' ? 'pgsql' : 'mysql';
            if (!is_string($dsn) || $dsn === '' || !in_array($driver, PDO::getAvailableDrivers(), true)) {
                continue;
            }
            $user = getenv($prefix . '_USER');
            $password = getenv($prefix . '_PASSWORD');
            $connections[$name] = new PDO($dsn, is_string($user) ? $user : null, is_string($password) ? $password : null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        return $connections;
    }

    /** @param list<mixed> $parameters */
    private function scalar(PDO $pdo, string $sql, array $parameters): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }
}
