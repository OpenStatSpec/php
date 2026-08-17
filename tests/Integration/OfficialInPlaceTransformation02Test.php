<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Tests\Support\SpecificationManifest;
use OpenStatSpec\Transformation\Audit\TransformationAuditMigrator;
use OpenStatSpec\Transformation\Audit\TransformationAuditWriter;
use OpenStatSpec\Transformation\Execution\InPlaceApplyRequest;
use OpenStatSpec\Transformation\Execution\InPlaceTransformationExecutor;
use OpenStatSpec\Transformation\Plan\PlanCodec;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class OfficialInPlaceTransformation02Test extends TestCase
{
    private const CREATE_DATASET_ID = '22222222-2222-4222-8222-222222222222';
    private const INEQUALITY_DATASET_ID = '44444444-4444-4444-8444-444444444444';
    private const ROLLBACK_DATASET_ID = '55555555-5555-4555-8555-555555555555';

    public function testSqliteCreateTargetIsAtomicAndPreservesIdentity(): void
    {
        $case = $this->bindingCase('sqlite-create-target-atomic-success');
        $pdo = $this->sqlite();
        $connection = new Connection($pdo);
        $this->installNumericFixture(
            $pdo,
            $connection,
            self::CREATE_DATASET_ID,
            'data_atomic_create',
            ['source_a', 'source_b'],
            [[1, 2.0, 11.0], [2, null, 22.0]],
        );
        $before = $this->snapshot($pdo, $connection, self::CREATE_DATASET_ID, 'data_atomic_create');
        $request = $this->request($case, self::CREATE_DATASET_ID);

        $result = (new InPlaceTransformationExecutor($connection))->execute($request);

        self::assertSame($case['expected_audit']['plan_hash'], $result->planHash());
        self::assertSame(self::CREATE_DATASET_ID, $result->datasetId());
        self::assertSame(1, $result->operationCount());
        self::assertNotSame('', $result->auditOperationId());
        $after = $this->snapshot($pdo, $connection, self::CREATE_DATASET_ID, 'data_atomic_create');
        self::assertSame($before['dataset_count'], $after['dataset_count']);
        self::assertSame($before['persistent_data_table_count'], $after['persistent_data_table_count']);
        self::assertSame($before['dataset_identity'], $after['dataset_identity']);
        self::assertSame($before['tables'], $after['tables']);
        self::assertSame($before['case_ordinals'], $after['case_ordinals']);
        self::assertSame($before['case_count'], $after['case_count']);
        self::assertSame(3, $after['variable_count']);
        self::assertSame(
            $case['after']['rows'],
            $this->numericRows($pdo, $connection, 'data_atomic_create', ['source_a', 'source_b', 'target']),
        );
        self::assertSame(
            [
                'source_ordinal' => 3,
                'storage_kind' => 'numeric',
                'declared_string_width' => null,
                'variable_label' => null,
                'print_format_family' => null,
                'print_format_width' => null,
                'print_format_decimals' => null,
                'write_format_family' => null,
                'write_format_width' => null,
                'write_format_decimals' => null,
                'measurement_level' => null,
                'variable_role' => null,
                'display_width' => null,
                'display_alignment' => null,
            ],
            $this->targetMetadata($pdo, self::CREATE_DATASET_ID, 'target'),
        );
        self::assertSame([], $this->valueLabels($pdo, self::CREATE_DATASET_ID, 'target'));
        self::assertSame([], $this->missingRules($pdo, self::CREATE_DATASET_ID, 'target'));
        $this->assertAudit($pdo, $case['expected_audit']);
    }

    public function testSqliteCreateTargetFailureRollsBackSchemaRowsCatalogAndAudit(): void
    {
        $case = $this->bindingCase('sqlite-create-target-atomic-success');
        $pdo = $this->sqlite();
        $connection = new Connection($pdo);
        $this->installNumericFixture(
            $pdo,
            $connection,
            self::CREATE_DATASET_ID,
            'data_atomic_create',
            ['source_a', 'source_b'],
            [[1, 2.0, 11.0], [2, null, 22.0]],
        );
        $before = $this->snapshot($pdo, $connection, self::CREATE_DATASET_ID, 'data_atomic_create');
        $failingAuditPdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        try {
            (new InPlaceTransformationExecutor(
                $connection,
                auditWriter: new TransformationAuditWriter($failingAuditPdo),
            ))->execute($this->request($case, self::CREATE_DATASET_ID));
            self::fail('The injected audit failure did not abort the apply.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('open apply transaction', $exception->getMessage());
        }

        $after = $this->snapshot($pdo, $connection, self::CREATE_DATASET_ID, 'data_atomic_create');
        self::assertSame($before, $after);
        self::assertNotContains('target', $this->columns($pdo, $connection, 'data_atomic_create'));
        self::assertSame(0, $this->scalarCount($pdo, 'SELECT COUNT(*) FROM variable WHERE dataset_id = ? AND source_name = ?', [self::CREATE_DATASET_ID, 'target']));
        self::assertSame(0, $this->scalarCount($pdo, 'SELECT COUNT(*) FROM transformation_apply', []));
        self::assertFalse($pdo->inTransaction());
    }

    public function testSqliteInequalityBoundariesUseSqlThreeValuedTruth(): void
    {
        $case = $this->bindingCase('sqlite-inequality-boundary-semantics');
        $pdo = $this->sqlite();
        $connection = new Connection($pdo);
        $this->installNumericFixture(
            $pdo,
            $connection,
            self::INEQUALITY_DATASET_ID,
            'data_inequality',
            ['source', 'target_lt', 'target_le', 'target_gt', 'target_ge'],
            [[1, 0.0, 0.0, 0.0, 0.0, 0.0], [2, 1.0, 0.0, 0.0, 0.0, 0.0], [3, 2.0, 0.0, 0.0, 0.0, 0.0]],
        );
        $before = $this->snapshot($pdo, $connection, self::INEQUALITY_DATASET_ID, 'data_inequality');

        $result = (new InPlaceTransformationExecutor($connection))->execute($this->request($case, self::INEQUALITY_DATASET_ID));

        self::assertSame($case['expected_audit']['plan_hash'], $result->planHash());
        self::assertSame($case['after_rows'], $this->numericRows(
            $pdo,
            $connection,
            'data_inequality',
            ['source', 'target_lt', 'target_le', 'target_gt', 'target_ge'],
        ));
        $after = $this->snapshot($pdo, $connection, self::INEQUALITY_DATASET_ID, 'data_inequality');
        self::assertSame($before['dataset_count'], $after['dataset_count']);
        self::assertSame($before['persistent_data_table_count'], $after['persistent_data_table_count']);
        self::assertSame($before['dataset_identity'], $after['dataset_identity']);
        self::assertSame($before['tables'], $after['tables']);
        self::assertSame($before['variables'], $after['variables']);
        self::assertSame($before['case_ordinals'], $after['case_ordinals']);
        self::assertSame($before['case_count'], $after['case_count']);
        $this->assertAudit($pdo, $case['expected_audit']);
    }

    public function testSqliteOperationsObservePriorValuesAndChangeOnlyNamedMetadata(): void
    {
        $case = $this->bindingCase('dolt-preprovisioned-target-sequential-null-semantics');
        $pdo = $this->sqlite();
        $connection = new Connection($pdo);
        $this->installNumericFixture(
            $pdo,
            $connection,
            '11111111-1111-4111-8111-111111111111',
            'data_synthetic',
            ['source_a', 'source_b', 'target'],
            [[1, 1.0, 1.0, null], [2, 1.0, null, null], [3, null, 1.0, null], [4, 0.0, 1.0, null]],
        );
        $targetId = (string) $this->scalar(
            $pdo,
            'SELECT variable_id FROM variable WHERE dataset_id = ? AND source_name = ?',
            ['11111111-1111-4111-8111-111111111111', 'target'],
        );
        $pdo->prepare(
            'INSERT INTO missing_rule (missing_rule_id, variable_id, ordinal, rule_kind, code_kind, numeric_value) VALUES (?, ?, ?, ?, ?, ?)',
        )->execute(['55555555-5555-4555-8555-555555555555', $targetId, 1, 'discrete', 'numeric', -9.0]);
        $request = new InPlaceApplyRequest(
            plan: $this->planCase((string) $case['applied_plan_case']),
            inputAlias: 'parent',
            datasetId: '11111111-1111-4111-8111-111111111111',
            sourceHash: (string) $case['expected_audit']['source_hash'],
            actor: 'conformance-runner',
        );

        (new InPlaceTransformationExecutor($connection))->execute($request);

        self::assertSame($case['after']['rows'], $this->numericRows(
            $pdo,
            $connection,
            'data_synthetic',
            ['source_a', 'source_b', 'target'],
        ));
        self::assertSame(
            [
                'source_ordinal' => 3,
                'storage_kind' => 'numeric',
                'declared_string_width' => null,
                'variable_label' => 'Synthetic conjunction',
                'print_format_family' => 'F',
                'print_format_width' => 1,
                'print_format_decimals' => 0,
                'write_format_family' => 'F',
                'write_format_width' => 1,
                'write_format_decimals' => 0,
                'measurement_level' => 'nominal',
                'variable_role' => null,
                'display_width' => null,
                'display_alignment' => null,
            ],
            $this->targetMetadata($pdo, '11111111-1111-4111-8111-111111111111', 'target'),
        );
        self::assertSame([[0.0, 'No'], [1.0, 'Yes']], $this->valueLabels($pdo, '11111111-1111-4111-8111-111111111111', 'target'));
        self::assertSame([['ordinal' => 1, 'rule_kind' => 'discrete', 'code_kind' => 'numeric', 'numeric_value' => -9.0]], $this->missingRules($pdo, '11111111-1111-4111-8111-111111111111', 'target'));
    }

    public function testPostgresqlCreateTargetIsAtomicWhenConfigured(): void
    {
        $pdo = $this->postgresql();
        $connection = new Connection($pdo);
        self::assertSame('postgresql', $connection->profileName);
        $table = 'data_atomic_create_task8';
        $case = $this->bindingCase('sqlite-create-target-atomic-success');
        $this->prepareCatalog($pdo);
        $this->assertNamespaceClean($pdo, self::CREATE_DATASET_ID, $table);

        try {
            $this->installNumericFixture(
                $pdo,
                $connection,
                self::CREATE_DATASET_ID,
                $table,
                ['source_a', 'source_b'],
                [[1, 2.0, 11.0], [2, null, 22.0]],
            );
            $before = $this->snapshot($pdo, $connection, self::CREATE_DATASET_ID, $table);
            (new InPlaceTransformationExecutor($connection))->execute($this->request($case, self::CREATE_DATASET_ID));
            $after = $this->snapshot($pdo, $connection, self::CREATE_DATASET_ID, $table);
            self::assertSame($before['dataset_count'], $after['dataset_count']);
            self::assertSame($before['persistent_data_table_count'], $after['persistent_data_table_count']);
            self::assertSame($before['dataset_identity'], $after['dataset_identity']);
            self::assertSame($before['tables'], $after['tables']);
            self::assertSame($case['after']['rows'], $this->numericRows($pdo, $connection, $table, ['source_a', 'source_b', 'target']));
            $this->assertAudit($pdo, [...$case['expected_audit'], 'database_profile' => 'postgresql', 'physical_table_name' => $table]);
        } finally {
            $this->purgeFixture($pdo, $connection, self::CREATE_DATASET_ID, $table);
        }
    }

    public function testPostgresqlCreateTargetFailureRollsBackWhenConfigured(): void
    {
        $pdo = $this->postgresql();
        $connection = new Connection($pdo);
        $table = 'data_atomic_rollback_task8';
        $case = $this->bindingCase('sqlite-create-target-atomic-success');
        $this->prepareCatalog($pdo);
        $this->assertNamespaceClean($pdo, self::ROLLBACK_DATASET_ID, $table);

        try {
            $this->installNumericFixture(
                $pdo,
                $connection,
                self::ROLLBACK_DATASET_ID,
                $table,
                ['source_a', 'source_b'],
                [[1, 2.0, 11.0], [2, null, 22.0]],
            );
            $before = $this->snapshot($pdo, $connection, self::ROLLBACK_DATASET_ID, $table);
            $failingAuditPdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            try {
                (new InPlaceTransformationExecutor(
                    $connection,
                    auditWriter: new TransformationAuditWriter($failingAuditPdo),
                ))->execute($this->request($case, self::ROLLBACK_DATASET_ID));
                self::fail('The injected PostgreSQL audit failure did not abort the apply.');
            } catch (\LogicException $exception) {
                self::assertStringContainsString('open apply transaction', $exception->getMessage());
            }

            self::assertSame($before, $this->snapshot($pdo, $connection, self::ROLLBACK_DATASET_ID, $table));
            self::assertNotContains('target', $this->columns($pdo, $connection, $table));
            self::assertFalse($pdo->inTransaction());
        } finally {
            $this->purgeFixture($pdo, $connection, self::ROLLBACK_DATASET_ID, $table);
        }
    }

    private function sqlite(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available.');
        }
        return new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    private function postgresql(): PDO
    {
        $dsn = getenv('OPENSTATSPEC_PG_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('OPENSTATSPEC_PG_DSN is not configured.');
        }
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO PostgreSQL is not available.');
        }
        $user = getenv('OPENSTATSPEC_PG_USER');
        $password = getenv('OPENSTATSPEC_PG_PASSWORD');
        return new PDO(
            $dsn,
            is_string($user) ? $user : null,
            is_string($password) ? $password : null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => false],
        );
    }

    /**
     * @param list<string>               $variables
     * @param list<list<int|float|null>> $rows
     */
    private function installNumericFixture(
        PDO $pdo,
        Connection $connection,
        string $datasetId,
        string $table,
        array $variables,
        array $rows,
    ): void {
        if ($connection->profileName === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
        $this->prepareCatalog($pdo);
        $quotedColumns = array_map($connection->profile->quoteIdentifier(...), $variables);
        $pdo->exec(
            'CREATE TABLE ' . $connection->profile->quoteIdentifier($table) . ' ('
            . $connection->profile->quoteIdentifier('__case_ordinal') . ' BIGINT NOT NULL PRIMARY KEY, '
            . implode(' ' . $connection->profile->numericType() . ' NULL, ', $quotedColumns)
            . ' ' . $connection->profile->numericType() . ' NULL)',
        );
        $pdo->prepare(
            'INSERT INTO dataset (dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, dataset_name, source_case_count, imported_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$datasetId, '1.0', 'fixture', null, $table, 'Task 8 fixture', count($rows), '2026-08-17 00:00:00']);
        $insertVariable = $pdo->prepare(
            'INSERT INTO variable (variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind) VALUES (?, ?, ?, ?, ?, ?)',
        );
        foreach ($variables as $index => $variable) {
            $insertVariable->execute([
                $this->fixtureUuid($datasetId . ':' . $variable),
                $datasetId,
                $index + 1,
                $variable,
                $variable,
                'numeric',
            ]);
        }
        $columns = [$connection->profile->quoteIdentifier('__case_ordinal'), ...$quotedColumns];
        $insertCase = $pdo->prepare(
            'INSERT INTO ' . $connection->profile->quoteIdentifier($table)
            . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
        );
        foreach ($rows as $row) {
            $insertCase->execute($row);
        }
    }

    private function prepareCatalog(PDO $pdo): void
    {
        (new NormativeCatalog($pdo))->createTables();
        (new TransformationAuditMigrator($pdo))->migrate();
        CatalogOwnership::markCurrentVersion($pdo);
    }

    /** @param array<string, mixed> $case */
    private function request(array $case, string $datasetId): InPlaceApplyRequest
    {
        return new InPlaceApplyRequest(
            plan: $this->planCase((string) $case['applied_plan_case']),
            inputAlias: 'parent',
            datasetId: $datasetId,
            sourceHash: (string) $case['expected_audit']['source_hash'],
            actor: (string) $case['actor'],
        );
    }

    /** @return array<string, mixed> */
    private function bindingCase(string $id): array
    {
        foreach (SpecificationManifest::load('conformance/in-place-transformation-0.2.json')['cases'] as $case) {
            if (is_array($case) && ($case['id'] ?? null) === $id) {
                return $case;
            }
        }
        throw new \RuntimeException('Missing in-place fixture: ' . $id);
    }

    private function planCase(string $id): \OpenStatSpec\Transformation\Plan\TransformationPlan
    {
        foreach (SpecificationManifest::load('conformance/transformation-plan-0.2.json')['cases'] as $case) {
            if (is_array($case) && ($case['id'] ?? null) === $id && is_array($case['plan'] ?? null)) {
                return (new PlanCodec())->fromArray($case['plan']);
            }
        }
        throw new \RuntimeException('Missing plan fixture: ' . $id);
    }

    /** @return array<string, mixed> */
    private function snapshot(PDO $pdo, Connection $connection, string $datasetId, string $table): array
    {
        return [
            'dataset_count' => $this->scalarCount($pdo, 'SELECT COUNT(*) FROM dataset', []),
            'persistent_data_table_count' => $this->scalarCount($pdo, 'SELECT COUNT(DISTINCT physical_table_name) FROM dataset', []),
            'dataset_identity' => $this->rows($pdo, 'SELECT dataset_id, physical_table_schema, physical_table_name FROM dataset WHERE dataset_id = ?', [$datasetId]),
            'tables' => $this->tables($pdo),
            'columns' => $this->columns($pdo, $connection, $table),
            'variables' => $this->rows($pdo, 'SELECT * FROM variable WHERE dataset_id = ? ORDER BY source_ordinal', [$datasetId]),
            'variable_count' => $this->scalarCount($pdo, 'SELECT COUNT(*) FROM variable WHERE dataset_id = ?', [$datasetId]),
            'case_ordinals' => array_map('intval', $this->column($pdo, 'SELECT __case_ordinal FROM ' . $connection->profile->quoteIdentifier($table) . ' ORDER BY __case_ordinal')),
            'case_count' => $this->scalarCount($pdo, 'SELECT COUNT(*) FROM ' . $connection->profile->quoteIdentifier($table), []),
            'rows' => $this->rows($pdo, 'SELECT * FROM ' . $connection->profile->quoteIdentifier($table) . ' ORDER BY __case_ordinal', []),
            'audit_count' => $this->scalarCount($pdo, 'SELECT COUNT(*) FROM transformation_apply', []),
        ];
    }

    /**
     * @param list<string> $variables
     * @return list<array<string, int|float|null>>
     */
    private function numericRows(PDO $pdo, Connection $connection, string $table, array $variables): array
    {
        $columns = ['__case_ordinal', ...$variables];
        $rows = $this->rows(
            $pdo,
            'SELECT ' . implode(', ', array_map($connection->profile->quoteIdentifier(...), $columns))
            . ' FROM ' . $connection->profile->quoteIdentifier($table)
            . ' ORDER BY ' . $connection->profile->quoteIdentifier('__case_ordinal'),
            [],
        );
        return array_map(static function (array $row) use ($columns): array {
            $normalized = [];
            foreach ($columns as $column) {
                $value = $row[$column];
                if ($column === '__case_ordinal') {
                    $normalized[$column] = (int) $value;
                    continue;
                }
                if ($value === null) {
                    $normalized[$column] = null;
                    continue;
                }
                $number = (float) $value;
                $normalized[$column] = floor($number) === $number ? (int) $number : $number;
            }
            return $normalized;
        }, $rows);
    }

    /** @return array<string, int|string|null> */
    private function targetMetadata(PDO $pdo, string $datasetId, string $target): array
    {
        $rows = $this->rows(
            $pdo,
            'SELECT source_ordinal, storage_kind, declared_string_width, variable_label, print_format_family, print_format_width, '
            . 'print_format_decimals, write_format_family, write_format_width, write_format_decimals, measurement_level, '
            . 'variable_role, display_width, display_alignment FROM variable WHERE dataset_id = ? AND source_name = ?',
            [$datasetId, $target],
        );
        self::assertCount(1, $rows);
        foreach (['source_ordinal', 'declared_string_width', 'print_format_width', 'print_format_decimals', 'write_format_width', 'write_format_decimals', 'display_width'] as $integer) {
            if ($rows[0][$integer] !== null) {
                $rows[0][$integer] = (int) $rows[0][$integer];
            }
        }
        return $rows[0];
    }

    /** @return list<array{0: float|string, 1: string}> */
    private function valueLabels(PDO $pdo, string $datasetId, string $target): array
    {
        $rows = $this->rows(
            $pdo,
            'SELECT label.code_kind, label.numeric_code, label.string_code, label.label FROM variable '
            . 'JOIN variable_value_label_set link ON link.variable_id = variable.variable_id '
            . 'JOIN value_label label ON label.value_label_set_id = link.value_label_set_id '
            . 'WHERE variable.dataset_id = ? AND variable.source_name = ? ORDER BY label.ordinal',
            [$datasetId, $target],
        );
        return array_map(static fn(array $row): array => [
            $row['code_kind'] === 'numeric' ? (float) $row['numeric_code'] : (string) $row['string_code'],
            (string) $row['label'],
        ], $rows);
    }

    /** @return list<array{ordinal: int, rule_kind: string, code_kind: string, numeric_value: float|null}> */
    private function missingRules(PDO $pdo, string $datasetId, string $target): array
    {
        return array_map(static fn(array $row): array => [
            'ordinal' => (int) $row['ordinal'],
            'rule_kind' => (string) $row['rule_kind'],
            'code_kind' => (string) $row['code_kind'],
            'numeric_value' => $row['numeric_value'] === null ? null : (float) $row['numeric_value'],
        ], $this->rows(
            $pdo,
            'SELECT missing_rule.ordinal, missing_rule.rule_kind, missing_rule.code_kind, missing_rule.numeric_value '
            . 'FROM variable JOIN missing_rule ON missing_rule.variable_id = variable.variable_id '
            . 'WHERE variable.dataset_id = ? AND variable.source_name = ? ORDER BY missing_rule.ordinal',
            [$datasetId, $target],
        ));
    }

    /** @param array<string, mixed> $expected */
    private function assertAudit(PDO $pdo, array $expected): void
    {
        $rows = $this->rows($pdo, 'SELECT * FROM transformation_apply ORDER BY started_at, apply_id', []);
        self::assertCount(1, $rows);
        foreach ($expected as $field => $value) {
            self::assertArrayHasKey($field, $rows[0]);
            self::assertSame($value, $field === 'operation_count' ? (int) $rows[0][$field] : $rows[0][$field], $field);
        }
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $rows[0]['apply_id']);
        self::assertNotSame('', (string) $rows[0]['started_at']);
        self::assertNotSame('', (string) $rows[0]['completed_at']);
    }

    private function assertNamespaceClean(PDO $pdo, string $datasetId, string $table): void
    {
        self::assertSame(0, $this->scalarCount($pdo, 'SELECT COUNT(*) FROM dataset WHERE dataset_id = ? OR physical_table_name = ?', [$datasetId, $table]));
        self::assertNotContains($table, $this->tables($pdo));
    }

    private function purgeFixture(PDO $pdo, Connection $connection, string $datasetId, string $table): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->prepare('DELETE FROM transformation_apply WHERE dataset_id = ?')->execute([$datasetId]);
        $pdo->prepare('DELETE FROM variable_value_label_set WHERE variable_id IN (SELECT variable_id FROM variable WHERE dataset_id = ?)')->execute([$datasetId]);
        $pdo->prepare('DELETE FROM value_label WHERE value_label_set_id IN (SELECT value_label_set_id FROM value_label_set WHERE dataset_id = ?)')->execute([$datasetId]);
        $pdo->prepare('DELETE FROM value_label_set WHERE dataset_id = ?')->execute([$datasetId]);
        $pdo->prepare('DELETE FROM missing_rule WHERE variable_id IN (SELECT variable_id FROM variable WHERE dataset_id = ?)')->execute([$datasetId]);
        $pdo->prepare('DELETE FROM variable WHERE dataset_id = ?')->execute([$datasetId]);
        $pdo->prepare('DELETE FROM dataset WHERE dataset_id = ?')->execute([$datasetId]);
        $pdo->exec('DROP TABLE IF EXISTS ' . $connection->profile->quoteIdentifier($table));
    }

    /** @return list<string> */
    private function tables(PDO $pdo): array
    {
        $sql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            : "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = current_schema() ORDER BY tablename";
        return array_map('strval', $this->column($pdo, $sql));
    }

    /** @return list<string> */
    private function columns(PDO $pdo, Connection $connection, string $table): array
    {
        if ($connection->profileName === 'sqlite') {
            return array_map(
                static fn(array $row): string => (string) $row['name'],
                $this->rows($pdo, 'PRAGMA table_info(' . $connection->profile->quoteIdentifier($table) . ')', []),
            );
        }
        return array_map('strval', $this->column(
            $pdo,
            'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position',
            [$table],
        ));
    }

    private function fixtureUuid(string $scope): string
    {
        $hash = hash('sha256', $scope);
        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-4' . substr($hash, 12, 3) . '-8' . substr($hash, 15, 3) . '-' . substr($hash, 18, 12);
    }

    /** @param list<mixed> $parameters */
    private function scalarCount(PDO $pdo, string $sql, array $parameters): int
    {
        return (int) $this->scalar($pdo, $sql, $parameters);
    }

    /** @param list<mixed> $parameters */
    private function scalar(PDO $pdo, string $sql, array $parameters): mixed
    {
        $statement = $pdo->prepare($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }

    /**
     * @param list<mixed> $parameters
     * @return list<mixed>
     */
    private function column(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute($parameters);
        return array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(PDO $pdo, string $sql, array $parameters): array
    {
        $statement = $pdo->prepare($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute($parameters);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
