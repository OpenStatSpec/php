<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Tests\Support\OfficialFixturePdoAccessors;
use OpenStatSpec\Tests\Support\SpecificationManifest;
use OpenStatSpec\Transformation\Audit\TransformationAuditMigrator;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Execution\DoltEvidence;
use OpenStatSpec\Transformation\Execution\DoltEvidenceReader;
use OpenStatSpec\Transformation\Execution\InPlaceApplyRequest;
use OpenStatSpec\Transformation\Execution\InPlaceTransformationExecutor;
use OpenStatSpec\Transformation\Plan\PlanCodec;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OfficialInPlaceTransformation02Test extends TestCase
{
    use OfficialFixturePdoAccessors;

    private const CREATE_DATASET_ID = '22222222-2222-4222-8222-222222222222';
    private const INEQUALITY_DATASET_ID = '44444444-4444-4444-8444-444444444444';
    private const ROLLBACK_DATASET_ID = '55555555-5555-4555-8555-555555555555';

    /** @var list<array{admin: PDO, database: string}> */
    private array $doltTestDatabases = [];

    protected function tearDown(): void
    {
        try {
            foreach (array_reverse($this->doltTestDatabases) as $fixture) {
                $fixture['admin']->exec('DROP DATABASE ' . $this->quoteDoltDatabase($fixture['database']));
            }
        } finally {
            $this->doltTestDatabases = [];
            parent::tearDown();
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function officialBackendCases(): iterable
    {
        foreach (SpecificationManifest::load('conformance/in-place-transformation-0.2.json')['cases'] as $case) {
            if (is_array($case) && is_string($case['id'] ?? null)) {
                yield $case['id'] => [$case];
            }
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('officialBackendCases')]
    public function testEveryOfficialBackendManifestCaseRuns(array $case): void
    {
        $id = $case['id'] ?? null;
        self::assertIsString($id);

        match ($id) {
            'dolt-preprovisioned-target-sequential-null-semantics',
            'dolt-preprovisioned-target-or-null-semantics',
            'dolt-preprovisioned-target-variable-missing-propagation',
            'dolt-preprovisioned-target-conditional-variable-missing-propagation' => $this->assertOfficialDoltSuccess($case),
            'dolt-create-target-fails-before-mutation',
            'mysql-create-target-fails-before-mutation',
            'mariadb-create-target-fails-before-mutation' => $this->assertOfficialCreateTargetRejection($case),
            'sqlite-inequality-boundary-semantics' => $this->assertOfficialSqliteInequality($case),
            'dolt-context-changed-after-mutation-rolls-back' => $this->assertOfficialDoltContextChanged($case),
            'dolt-empty-actor-fails-before-mutation' => $this->assertOfficialDoltEmptyActor($case),
            'sqlite-create-target-atomic-success' => $this->assertOfficialSqliteCreate($case),
            default => throw new RuntimeException('Unhandled official in-place 0.2 case: ' . $id),
        };
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
        $pdo->exec("ALTER TABLE transformation_apply ADD COLUMN task8_actor_guard INTEGER NOT NULL DEFAULT 0 CHECK (actor <> 'conformance-runner')");
        $before = $this->snapshot($pdo, $connection, self::CREATE_DATASET_ID, 'data_atomic_create');

        try {
            (new InPlaceTransformationExecutor($connection))->execute($this->request($case, self::CREATE_DATASET_ID));
            self::fail('The injected audit failure did not abort the apply.');
        } catch (\PDOException $exception) {
            self::assertStringContainsString('constraint', strtolower($exception->getMessage()));
        }

        $after = $this->snapshot($pdo, $connection, self::CREATE_DATASET_ID, 'data_atomic_create');
        self::assertSame($before, $after);
        self::assertNotContains('target', $this->columns($pdo, $connection, 'data_atomic_create'));
        self::assertSame(0, $this->scalarCount($pdo, 'SELECT COUNT(*) FROM variable WHERE dataset_id = ? AND source_name = ?', [self::CREATE_DATASET_ID, 'target']));
        self::assertSame(0, $this->scalarCount($pdo, 'SELECT COUNT(*) FROM transformation_apply', []));
        self::assertFalse($pdo->inTransaction());
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
        $auditGuardInstalled = false;

        try {
            $this->installNumericFixture(
                $pdo,
                $connection,
                self::ROLLBACK_DATASET_ID,
                $table,
                ['source_a', 'source_b'],
                [[1, 2.0, 11.0], [2, null, 22.0]],
            );
            $pdo->exec('ALTER TABLE transformation_apply DROP CONSTRAINT IF EXISTS task8_fail_audit');
            $pdo->exec("ALTER TABLE transformation_apply ADD CONSTRAINT task8_fail_audit CHECK (actor <> 'conformance-runner')");
            $auditGuardInstalled = true;
            $before = $this->snapshot($pdo, $connection, self::ROLLBACK_DATASET_ID, $table);

            try {
                (new InPlaceTransformationExecutor($connection))->execute($this->request($case, self::ROLLBACK_DATASET_ID));
                self::fail('The injected PostgreSQL audit failure did not abort the apply.');
            } catch (\PDOException $exception) {
                self::assertStringContainsString('task8_fail_audit', $exception->getMessage());
            }

            self::assertSame($before, $this->snapshot($pdo, $connection, self::ROLLBACK_DATASET_ID, $table));
            self::assertNotContains('target', $this->columns($pdo, $connection, $table));
            self::assertFalse($pdo->inTransaction());
        } finally {
            if ($auditGuardInstalled) {
                $pdo->exec('ALTER TABLE transformation_apply DROP CONSTRAINT IF EXISTS task8_fail_audit');
            }
            $this->purgeFixture($pdo, $connection, self::ROLLBACK_DATASET_ID, $table);
        }
    }

    /** @param array<string, mixed> $case */
    private function assertOfficialCreateTargetRejection(array $case): void
    {
        $profile = $case['database_profile'] ?? null;
        self::assertContains($profile, ['mysql', 'mariadb', 'dolt']);
        $pdo = $this->mysqlFamily((string) $profile);
        $connection = new Connection($pdo);
        self::assertSame($profile, $connection->profileName);
        $table = 'data_task9_create_' . $profile;
        $this->checkoutDoltFixtureBranch($pdo, $connection, $case);
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
            $context = $this->commitAndReadDoltFixtureContext($pdo, $connection);
            $before = $this->snapshot($pdo, $connection, self::CREATE_DATASET_ID, $table);
            $request = $this->request(
                $this->bindingCase('sqlite-create-target-atomic-success'),
                self::CREATE_DATASET_ID,
                $context['branch'] ?? null,
                $context['head'] ?? null,
            );

            try {
                (new InPlaceTransformationExecutor($connection))->execute($request);
                self::fail('A non-atomic MySQL-family profile accepted an official create-target plan.');
            } catch (TransformationFailure $failure) {
                self::assertSame($case['expected_error'], $failure->diagnosticCode());
            }

            self::assertFalse($pdo->inTransaction());
            self::assertFalse($case['mutation_started']);
            self::assertSame($before, $this->snapshot($pdo, $connection, self::CREATE_DATASET_ID, $table));
        } finally {
            $this->purgeFixture($pdo, $connection, self::CREATE_DATASET_ID, $table);
        }
    }

    /** @param array<string, mixed> $case */
    private function assertOfficialDoltSuccess(array $case): void
    {
        $pdo = $this->mysqlFamily('dolt');
        $connection = new Connection($pdo);
        self::assertSame('dolt', $connection->profileName);
        $this->checkoutDoltFixtureBranch($pdo, $connection, $case);
        $beforeContract = is_array($case['before'] ?? null) ? $case['before'] : [];
        $afterContract = is_array($case['after'] ?? null) ? $case['after'] : [];
        $beforeRows = $beforeContract['rows'] ?? $case['before_rows'] ?? null;
        $afterRows = $afterContract['rows'] ?? $case['after_rows'] ?? null;
        self::assertIsArray($beforeRows);
        self::assertIsArray($afterRows);
        $datasetId = $beforeContract['dataset_id'] ?? $case['dataset_id'] ?? null;
        $table = $beforeContract['physical_table_name'] ?? $case['physical_table_name'] ?? null;
        self::assertIsString($datasetId);
        self::assertIsString($table);
        [$variables, $fixtureRows] = $this->numericFixtureRows($beforeRows);
        $targetIdentity = is_array($beforeContract['target_identity'] ?? null)
            ? $beforeContract['target_identity']
            : [];
        $variableIds = is_string($targetIdentity['variable_id'] ?? null)
            ? ['target' => $targetIdentity['variable_id']]
            : [];

        $this->installNumericFixture($pdo, $connection, $datasetId, $table, $variables, $fixtureRows, $variableIds);
        $this->installOfficialMissingRules($pdo, $datasetId, $beforeContract);
        $context = $this->commitAndReadDoltFixtureContext($pdo, $connection);
        self::assertNotNull($context);
        $before = $this->snapshot($pdo, $connection, $datasetId, $table);
        $targetBefore = $this->targetIdentity($pdo, $datasetId, 'target');
        $metadataBefore = $this->targetMetadata($pdo, $datasetId, 'target');
        $labelsBefore = $this->valueLabels($pdo, $datasetId, 'target');
        $missingBefore = $this->missingRules($pdo, $datasetId, 'target');
        self::assertSame([], $before['repository']['status'] ?? null);

        $result = (new InPlaceTransformationExecutor($connection))->execute(
            $this->request($case, $datasetId, $context['branch'], $context['head']),
        );
        $after = $this->snapshot($pdo, $connection, $datasetId, $table);

        self::assertSame($datasetId, $result->datasetId());
        self::assertSame($case['expected_audit']['plan_hash'], $result->planHash());
        self::assertSame((int) $case['expected_audit']['operation_count'], $result->operationCount());
        self::assertSame($before['dataset_count'], $after['dataset_count']);
        self::assertSame($before['persistent_data_table_count'], $after['persistent_data_table_count']);
        self::assertSame($before['dataset_identity'], $after['dataset_identity']);
        self::assertSame($before['tables'], $after['tables']);
        self::assertSame($before['columns'], $after['columns']);
        self::assertSame($before['variable_count'], $after['variable_count']);
        self::assertSame($before['case_ordinals'], $after['case_ordinals']);
        self::assertSame($before['case_count'], $after['case_count']);
        self::assertSame($afterRows, $this->numericRows($pdo, $connection, $table, $variables));
        self::assertSame($targetBefore, $this->targetIdentity($pdo, $datasetId, 'target'));
        $this->assertDoltRepositoryApplyEvidence($before, $after, $table, (string) $case['id']);
        $this->assertNoForbiddenArtifactTables($after['tables']);

        if (($case['id'] ?? null) === 'dolt-preprovisioned-target-sequential-null-semantics') {
            self::assertSame(
                [
                    'variable_id' => $afterContract['target_identity']['variable_id'],
                    'source_ordinal' => $afterContract['target_identity']['ordinal'],
                    'source_name' => 'target',
                    'physical_name' => 'target',
                ],
                $targetBefore,
            );
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
                $this->targetMetadata($pdo, $datasetId, 'target'),
            );
            self::assertSame([[0.0, 'No'], [1.0, 'Yes']], $this->valueLabels($pdo, $datasetId, 'target'));
            self::assertSame($missingBefore, $this->missingRules($pdo, $datasetId, 'target'));
        } else {
            self::assertSame($metadataBefore, $this->targetMetadata($pdo, $datasetId, 'target'));
            self::assertSame($labelsBefore, $this->valueLabels($pdo, $datasetId, 'target'));
            self::assertSame($missingBefore, $this->missingRules($pdo, $datasetId, 'target'));
        }

        $audit = $this->assertAudit($pdo, [
            ...$case['expected_audit'],
            'dolt_branch' => $context['branch'],
            'dolt_head_before' => $context['head'],
            'dolt_head_after' => $context['head'],
        ]);
        foreach ($case['required_audit_fields'] ?? [] as $field) {
            self::assertArrayHasKey($field, $audit);
        }
    }

    /** @param array<string, mixed> $case */
    private function assertOfficialDoltContextChanged(array $case): void
    {
        $pdo = $this->mysqlFamily('dolt');
        $connection = new Connection($pdo);
        $this->checkoutDoltFixtureBranch($pdo, $connection, $case);
        $table = 'data_task9_context_change';
        $this->installNumericFixture(
            $pdo,
            $connection,
            self::ROLLBACK_DATASET_ID,
            $table,
            ['source_a', 'source_b', 'target'],
            [[1, 1.0, 1.0, 0.0]],
        );
        $pdo->prepare('UPDATE variable SET variable_label = ? WHERE dataset_id = ? AND source_name = ?')
            ->execute(['Before', self::ROLLBACK_DATASET_ID, 'target']);
        $context = $this->commitAndReadDoltFixtureContext($pdo, $connection);
        self::assertNotNull($context);
        $before = $this->snapshot($pdo, $connection, self::ROLLBACK_DATASET_ID, $table);
        self::assertSame($case['before']['rows'], $this->numericRows($pdo, $connection, $table, ['target']));
        self::assertSame($case['before']['target_metadata']['variable_label'], $this->scalar(
            $pdo,
            'SELECT variable_label FROM variable WHERE dataset_id = ? AND source_name = ?',
            [self::ROLLBACK_DATASET_ID, 'target'],
        ));
        $concurrentPdo = $this->doltConcurrentSession($pdo, $connection);
        $reader = new class ($pdo, $concurrentPdo, $connection, $table, self::ROLLBACK_DATASET_ID, $context['branch'], $context['head']) implements DoltEvidenceReader {
            public int $reads = 0;
            public bool $mutationObserved = false;

            public function __construct(
                private readonly PDO $executorPdo,
                private readonly PDO $concurrentPdo,
                private readonly Connection $connection,
                private readonly string $table,
                private readonly string $datasetId,
                private readonly string $branch,
                private readonly string $head,
            ) {}

            public function read(): DoltEvidence
            {
                if (++$this->reads === 1) {
                    return new DoltEvidence($this->branch, $this->head, []);
                }
                // The second PDO is a real concurrent session: make a DOLT_COMMIT
                // that does not touch the executor's target rows but moves HEAD,
                // so the executor must fail closed on dolt_context_changed.
                $metadata = $this->concurrentPdo->prepare(
                    'UPDATE dataset SET dataset_name = ? WHERE dataset_id = ?',
                );
                if ($metadata instanceof PDOStatement) {
                    $metadata->execute(['Concurrent commit probe', $this->datasetId]);
                }
                $commit = $this->concurrentPdo->prepare('CALL DOLT_COMMIT(?, ?)');
                if ($commit instanceof PDOStatement) {
                    $commit->execute(['-Am', 'Concurrent commit detected by executor guard']);
                }
                $head = $this->concurrentPdo->query("SELECT dolt_hashof('HEAD')");
                $newHead = $head instanceof PDOStatement ? (string) $head->fetchColumn() : $this->head;
                $statement = $this->executorPdo->query(
                    'SELECT ' . $this->connection->profile->quoteIdentifier('target')
                    . ' FROM ' . $this->connection->profile->quoteIdentifier($this->table)
                    . ' WHERE ' . $this->connection->profile->quoteIdentifier('__case_ordinal') . ' = 1',
                );
                $this->mutationObserved = $statement instanceof PDOStatement
                    && (float) $statement->fetchColumn() === 1.0;

                return new DoltEvidence($this->branch, $newHead, []);
            }
        };

        try {
            (new InPlaceTransformationExecutor($connection, $reader))->execute(new InPlaceApplyRequest(
                $this->planCase('sequential-conditional-binary-existing-target'),
                'parent',
                self::ROLLBACK_DATASET_ID,
                str_repeat('a', 64),
                (string) $case['actor'],
                $context['branch'],
                $context['head'],
            ));
            self::fail('The official post-mutation Dolt context change was accepted.');
        } catch (TransformationFailure $failure) {
            self::assertSame($case['expected_error'], $failure->diagnosticCode());
        }

        self::assertTrue($case['mutation_started']);
        self::assertTrue($reader->mutationObserved);
        self::assertFalse($pdo->inTransaction());
        self::assertSame($before, $this->snapshot($pdo, $connection, self::ROLLBACK_DATASET_ID, $table));
        self::assertSame($case['after_failure']['rows'], $this->numericRows($pdo, $connection, $table, ['target']));
        self::assertSame($case['after_failure']['target_metadata']['variable_label'], $this->scalar(
            $pdo,
            'SELECT variable_label FROM variable WHERE dataset_id = ? AND source_name = ?',
            [self::ROLLBACK_DATASET_ID, 'target'],
        ));
        self::assertSame($case['after_failure']['audit_row_count'], $this->scalarCount(
            $pdo,
            'SELECT COUNT(*) FROM transformation_apply',
            [],
        ));
    }

    /**
     * Opens a second PDO against the same Dolt database so the concurrent-commit
     * guard test can issue a real DOLT_COMMIT from an independent session
     * instead of faking the post-mutation evidence. The PDO is kept alive by
     * the anonymous reader closure that captures it; it is released (and
     * therefore disconnected) when the reader goes out of scope after the
     * executor throws.
     */
    private function doltConcurrentSession(PDO $primaryPdo, Connection $primaryConnection): PDO
    {
        $statement = $primaryPdo->query('SELECT DATABASE()');
        $database = $statement instanceof PDOStatement ? (string) $statement->fetchColumn() : '';
        self::assertNotSame('', $database, 'The primary Dolt session must be connected to a database.');
        $prefix = match ($primaryConnection->profileName) {
            'dolt' => 'OPENSTATSPEC_DOLT',
            'mysql' => 'OPENSTATSPEC_MYSQL',
            'mariadb' => 'OPENSTATSPEC_MARIADB',
            default => throw new RuntimeException('Concurrent session only supports MySQL-family profiles.'),
        };
        $baseDsn = getenv($prefix . '_DSN');
        if (!is_string($baseDsn) || $baseDsn === '') {
            self::markTestSkipped($prefix . '_DSN is not configured.');
        }
        $user = getenv($prefix . '_USER');
        $password = getenv($prefix . '_PASSWORD');
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => false];
        $concurrent = new PDO(
            $this->dsnForDatabase($baseDsn, $database),
            is_string($user) ? $user : null,
            is_string($password) ? $password : null,
            $options,
        );
        return $concurrent;
    }

    /** @param array<string, mixed> $case */
    private function assertOfficialDoltEmptyActor(array $case): void
    {
        $pdo = $this->mysqlFamily('dolt');
        $connection = new Connection($pdo);
        self::assertSame('dolt', $connection->profileName);
        $before = ['tables' => $this->tables($pdo), 'repository' => $this->doltRepositoryEvidence($pdo, $connection)];

        try {
            new InPlaceApplyRequest(
                $this->planCase('binding-variable-missing-existing-target'),
                'parent',
                '11111111-1111-4111-8111-111111111111',
                str_repeat('a', 64),
                (string) $case['actor'],
                'feature/recode',
                'provisioning-commit',
            );
            self::fail('The official empty actor was accepted.');
        } catch (TransformationFailure $failure) {
            self::assertSame($case['expected_error'], $failure->diagnosticCode());
        }

        self::assertFalse($case['mutation_started']);
        self::assertFalse($pdo->inTransaction());
        self::assertSame($before, [
            'tables' => $this->tables($pdo),
            'repository' => $this->doltRepositoryEvidence($pdo, $connection),
        ]);
    }

    /** @param array<string, mixed> $case */
    private function assertOfficialSqliteCreate(array $case): void
    {
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
        $result = (new InPlaceTransformationExecutor($connection))->execute(
            $this->request($case, self::CREATE_DATASET_ID),
        );
        $after = $this->snapshot($pdo, $connection, self::CREATE_DATASET_ID, 'data_atomic_create');

        self::assertSame($case['expected_audit']['plan_hash'], $result->planHash());
        self::assertSame(self::CREATE_DATASET_ID, $result->datasetId());
        self::assertSame(1, $result->operationCount());
        self::assertNotSame('', $result->auditOperationId());
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

    /** @param array<string, mixed> $case */
    private function assertOfficialSqliteInequality(array $case): void
    {
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
        $result = (new InPlaceTransformationExecutor($connection))->execute(
            $this->request($case, self::INEQUALITY_DATASET_ID),
        );
        $after = $this->snapshot($pdo, $connection, self::INEQUALITY_DATASET_ID, 'data_inequality');

        self::assertSame($case['expected_audit']['plan_hash'], $result->planHash());
        self::assertSame($case['after_rows'], $this->numericRows(
            $pdo,
            $connection,
            'data_inequality',
            ['source', 'target_lt', 'target_le', 'target_gt', 'target_ge'],
        ));
        self::assertSame($before['dataset_count'], $after['dataset_count']);
        self::assertSame($before['persistent_data_table_count'], $after['persistent_data_table_count']);
        self::assertSame($before['dataset_identity'], $after['dataset_identity']);
        self::assertSame($before['tables'], $after['tables']);
        self::assertSame($before['variables'], $after['variables']);
        self::assertSame($before['case_ordinals'], $after['case_ordinals']);
        self::assertSame($before['case_count'], $after['case_count']);
        $this->assertAudit($pdo, $case['expected_audit']);
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

    private function mysqlFamily(string $profile): PDO
    {
        $prefix = match ($profile) {
            'mysql' => 'OPENSTATSPEC_MYSQL',
            'mariadb' => 'OPENSTATSPEC_MARIADB',
            'dolt' => 'OPENSTATSPEC_DOLT',
            default => throw new RuntimeException('Unsupported MySQL-family test profile.'),
        };
        $dsn = getenv($prefix . '_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped($prefix . '_DSN is not configured.');
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('Configured MySQL-family service requires the PDO MySQL driver.');
        }
        $user = getenv($prefix . '_USER');
        $password = getenv($prefix . '_PASSWORD');
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => false];

        if ($profile !== 'dolt') {
            return new PDO(
                $dsn,
                is_string($user) ? $user : null,
                is_string($password) ? $password : null,
                $options,
            );
        }

        $adminUser = getenv($prefix . '_ADMIN_USER');
        $adminPassword = getenv($prefix . '_ADMIN_PASSWORD');
        if (!is_string($adminUser) || $adminUser === '' || !is_string($adminPassword)) {
            throw new RuntimeException('Configured Dolt service requires explicit admin credentials for isolation.');
        }
        $database = sprintf('openstatspec_t9_%d_%s', getmypid(), bin2hex(random_bytes(6)));
        $admin = new PDO($dsn, $adminUser, $adminPassword, $options);
        $admin->exec('CREATE DATABASE ' . $this->quoteDoltDatabase($database));
        $this->doltTestDatabases[] = ['admin' => $admin, 'database' => $database];
        if (is_string($user) && $user !== '') {
            $quotedUser = $admin->quote($user);
            if (!is_string($quotedUser)) {
                throw new RuntimeException('Unable to quote the Dolt test user.');
            }
            $admin->exec(
                'GRANT ALL PRIVILEGES ON ' . $this->quoteDoltDatabase($database) . ".* TO {$quotedUser}@'%'",
            );
        }

        return new PDO(
            $this->dsnForDatabase($dsn, $database),
            is_string($user) ? $user : null,
            is_string($password) ? $password : null,
            $options,
        );
    }

    /**
     * @param list<string>                 $variables
     * @param list<list<int|float|null>>   $rows
     * @param array<string, string>        $variableIds
     */
    private function installNumericFixture(
        PDO $pdo,
        Connection $connection,
        string $datasetId,
        string $table,
        array $variables,
        array $rows,
        array $variableIds = [],
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
                $variableIds[$variable] ?? $this->fixtureUuid($datasetId . ':' . $variable),
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
    private function request(
        array $case,
        string $datasetId,
        ?string $expectedBranch = null,
        ?string $expectedHead = null,
    ): InPlaceApplyRequest {
        return new InPlaceApplyRequest(
            plan: $this->planCase((string) $case['applied_plan_case']),
            inputAlias: 'parent',
            datasetId: $datasetId,
            sourceHash: (string) $case['expected_audit']['source_hash'],
            actor: (string) $case['actor'],
            expectedBranch: $expectedBranch,
            expectedHead: $expectedHead,
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
            'repository' => $this->doltRepositoryEvidence($pdo, $connection),
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

    /**
     * @param array<string, mixed> $expected
     * @return array<string, mixed>
     */
    private function assertAudit(PDO $pdo, array $expected): array
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

        return $rows[0];
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

    /** @return array{branch: string, head: string}|null */
    private function commitAndReadDoltFixtureContext(PDO $pdo, Connection $connection): ?array
    {
        if ($connection->profileName !== 'dolt') {
            return null;
        }
        $statement = $pdo->prepare('CALL DOLT_COMMIT(?, ?)');
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['-Am', 'Task 9 official create-target fixture']);
        $identity = $pdo->query("SELECT active_branch() AS branch_name, dolt_hashof('HEAD') AS head_hash");
        self::assertInstanceOf(PDOStatement::class, $identity);
        $row = $identity->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $status = $pdo->query('SELECT table_name FROM dolt_status');
        self::assertInstanceOf(PDOStatement::class, $status);
        self::assertSame([], $status->fetchAll(PDO::FETCH_COLUMN));

        return ['branch' => (string) $row['branch_name'], 'head' => (string) $row['head_hash']];
    }

    /** @param array<string, mixed> $case */
    private function checkoutDoltFixtureBranch(PDO $pdo, Connection $connection, array $case): void
    {
        if ($connection->profileName !== 'dolt') {
            return;
        }
        $expectedContext = is_array($case['expected_context'] ?? null) ? $case['expected_context'] : [];
        $branch = $case['expected_branch'] ?? $expectedContext['branch'] ?? null;
        if (!is_string($branch) || $branch === '') {
            throw new RuntimeException('Official Dolt case is missing its expected branch.');
        }
        $active = (string) $this->scalar($pdo, 'SELECT active_branch()', []);
        if ($active === $branch) {
            return;
        }
        $statement = $pdo->prepare('CALL DOLT_CHECKOUT(?, ?)');
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute(['-b', $branch]);
        self::assertSame($branch, $this->scalar($pdo, 'SELECT active_branch()', []));
    }

    /**
     * @param array<int, mixed> $rows
     * @return array{list<string>, list<list<int|float|null>>}
     */
    private function numericFixtureRows(array $rows): array
    {
        $first = $rows[0] ?? null;
        if (!is_array($first) || !is_int($first['__case_ordinal'] ?? null)) {
            throw new RuntimeException('Official numeric fixture rows are malformed.');
        }
        $variables = [];
        foreach (array_keys($first) as $column) {
            if (is_string($column) && $column !== '__case_ordinal') {
                $variables[] = $column;
            }
        }
        if ($variables === []) {
            throw new RuntimeException('Official numeric fixture has no variables.');
        }
        $fixtureRows = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_int($row['__case_ordinal'] ?? null)) {
                throw new RuntimeException('Official numeric fixture row is malformed.');
            }
            $fixtureRow = [$row['__case_ordinal']];
            foreach ($variables as $variable) {
                if (!array_key_exists($variable, $row)) {
                    throw new RuntimeException('Official numeric fixture row is missing a variable.');
                }
                $value = $row[$variable];
                if ($value !== null && !is_int($value) && !is_float($value)) {
                    throw new RuntimeException('Official numeric fixture value is not numeric or null.');
                }
                $fixtureRow[] = $value;
            }
            $fixtureRows[] = $fixtureRow;
        }

        return [$variables, $fixtureRows];
    }

    /** @param array<string, mixed> $beforeContract */
    private function installOfficialMissingRules(PDO $pdo, string $datasetId, array $beforeContract): void
    {
        $targetMetadata = is_array($beforeContract['target_metadata'] ?? null)
            ? $beforeContract['target_metadata']
            : [];
        $missingValues = $targetMetadata['missing_values'] ?? [];
        if (!is_array($missingValues) || $missingValues === []) {
            return;
        }
        $targetId = $this->scalar(
            $pdo,
            'SELECT variable_id FROM variable WHERE dataset_id = ? AND source_name = ?',
            [$datasetId, 'target'],
        );
        if (!is_string($targetId) || $targetId === '') {
            throw new RuntimeException('Official target variable identity is unavailable.');
        }
        $insert = $pdo->prepare(
            'INSERT INTO missing_rule (missing_rule_id, variable_id, ordinal, rule_kind, code_kind, numeric_value) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
        );
        self::assertInstanceOf(PDOStatement::class, $insert);
        foreach ($missingValues as $missing) {
            if (!is_array($missing) || !is_int($missing['ordinal'] ?? null)) {
                throw new RuntimeException('Official missing-value fixture is malformed.');
            }
            $insert->execute([
                $this->fixtureUuid($datasetId . ':missing:' . $missing['ordinal']),
                $targetId,
                $missing['ordinal'],
                $missing['rule_kind'] ?? null,
                $missing['code_kind'] ?? null,
                $missing['numeric_value'] ?? null,
            ]);
        }
    }

    /** @return array{variable_id: string, source_ordinal: int, source_name: string, physical_name: string} */
    private function targetIdentity(PDO $pdo, string $datasetId, string $target): array
    {
        $rows = $this->rows(
            $pdo,
            'SELECT variable_id, source_ordinal, source_name, physical_name FROM variable '
            . 'WHERE dataset_id = ? AND source_name = ?',
            [$datasetId, $target],
        );
        self::assertCount(1, $rows);

        return [
            'variable_id' => (string) $rows[0]['variable_id'],
            'source_ordinal' => (int) $rows[0]['source_ordinal'],
            'source_name' => (string) $rows[0]['source_name'],
            'physical_name' => (string) $rows[0]['physical_name'],
        ];
    }

    /** @return array{branch: string, head: string, history: list<string>, status: list<array{table_name: string, status: string, staged: bool}>}|null */
    private function doltRepositoryEvidence(PDO $pdo, Connection $connection): ?array
    {
        if ($connection->profileName !== 'dolt') {
            return null;
        }
        $identity = $this->rows(
            $pdo,
            "SELECT active_branch() AS branch_name, dolt_hashof('HEAD') AS head_hash",
            [],
        )[0] ?? [];
        $status = array_map(static function (array $row): array {
            $row = array_change_key_case($row, CASE_LOWER);

            return [
                'table_name' => (string) ($row['table_name'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'staged' => (bool) ($row['staged'] ?? false),
            ];
        }, $this->rows($pdo, 'SELECT table_name, status, staged FROM dolt_status ORDER BY table_name', []));

        return [
            'branch' => (string) ($identity['branch_name'] ?? ''),
            'head' => (string) ($identity['head_hash'] ?? ''),
            'history' => array_map('strval', $this->column(
                $pdo,
                'SELECT commit_hash FROM dolt_log ORDER BY commit_order DESC, commit_hash',
            )),
            'status' => $status,
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function assertDoltRepositoryApplyEvidence(array $before, array $after, string $table, string $caseId): void
    {
        $beforeRepository = $before['repository'] ?? null;
        $afterRepository = $after['repository'] ?? null;
        self::assertIsArray($beforeRepository);
        self::assertIsArray($afterRepository);
        self::assertSame($beforeRepository['branch'], $afterRepository['branch']);
        self::assertSame($beforeRepository['head'], $afterRepository['head']);
        self::assertSame($beforeRepository['history'], $afterRepository['history']);
        $changedTables = [$table, 'transformation_apply'];
        if ($caseId === 'dolt-preprovisioned-target-sequential-null-semantics') {
            $changedTables = [
                $table,
                'transformation_apply',
                'value_label',
                'value_label_set',
                'variable',
                'variable_value_label_set',
            ];
        }
        sort($changedTables);
        self::assertSame(array_map(static fn(string $changed): array => [
            'table_name' => $changed,
            'status' => 'modified',
            'staged' => false,
        ], $changedTables), $afterRepository['status']);
    }

    /** @param list<string> $tables */
    private function assertNoForbiddenArtifactTables(array $tables): void
    {
        self::assertSame([], array_values(array_filter(
            $tables,
            static fn(string $table): bool => preg_match('/derived|output|staging|snapshot|rollback|recovery/i', $table) === 1,
        )));
    }

    private function quoteDoltDatabase(string $database): string
    {
        if (strlen($database) > 64 || preg_match('/\A[a-z][a-z0-9_]*\z/D', $database) !== 1) {
            throw new RuntimeException('Unsafe Dolt test database name.');
        }

        return '`' . $database . '`';
    }

    private function dsnForDatabase(string $dsn, string $database): string
    {
        $this->quoteDoltDatabase($database);
        if (!str_starts_with(strtolower($dsn), 'mysql:')) {
            throw new RuntimeException('Dolt tests require a MySQL PDO DSN.');
        }
        $parts = explode(';', substr($dsn, strlen('mysql:')));
        $found = null;
        foreach ($parts as $index => $part) {
            if (strtolower(trim((string) explode('=', $part, 2)[0])) !== 'dbname') {
                continue;
            }
            if ($found !== null) {
                throw new RuntimeException('Dolt DSN contains duplicate dbname settings.');
            }
            $found = $index;
        }
        if ($found === null) {
            if (end($parts) === '') {
                array_pop($parts);
            }
            $parts[] = 'dbname=' . $database;
        } else {
            $parts[$found] = 'dbname=' . $database;
        }

        return 'mysql:' . implode(';', $parts);
    }

    /** @return list<string> */
    private function tables(PDO $pdo): array
    {
        $sql = match ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            'pgsql' => "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = current_schema() ORDER BY tablename",
            'mysql' => 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name',
            default => throw new RuntimeException('Unsupported integration driver.'),
        };
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
        $schema = $connection->profileName === 'postgresql' ? 'current_schema()' : 'DATABASE()';
        return array_map('strval', $this->column(
            $pdo,
            'SELECT column_name FROM information_schema.columns WHERE table_schema = ' . $schema . ' AND table_name = ? ORDER BY ordinal_position',
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
}
