<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

use OpenStatSpec\Frontend\Spss\Request\InputSchema;
use OpenStatSpec\Frontend\Spss\Request\InputVariable;
use OpenStatSpec\Frontend\Spss\Request\SpssFrontendRequest;
use OpenStatSpec\Frontend\Spss\SpssCompiler;
use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Tests\Support\OfficialFixturePdoAccessors;
use OpenStatSpec\Tests\Support\SpecificationManifest;
use OpenStatSpec\Transformation\Audit\TransformationAuditMigrator;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Execution\InPlaceApplyRequest;
use OpenStatSpec\Transformation\Execution\InPlaceTransformationExecutor;
use OpenStatSpec\Transformation\Plan\PlanCodec;
use OpenStatSpec\Transformation\Plan\TransformationPlan;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OfficialInPlaceTransformation01Test extends TestCase
{
    use OfficialFixturePdoAccessors;

    private const DATASET_ID = '66666666-6666-4666-8666-666666666666';

    /** @var list<array{admin: PDO, database: string}> */
    private array $isolatedTestDatabases = [];

    protected function tearDown(): void
    {
        try {
            foreach (array_reverse($this->isolatedTestDatabases) as $fixture) {
                $fixture['admin']->exec('DROP DATABASE ' . $this->quoteDatabase($fixture['database']));
            }
        } finally {
            $this->isolatedTestDatabases = [];
            parent::tearDown();
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function officialBackendCases(): iterable
    {
        foreach (SpecificationManifest::load('conformance/in-place-transformation-0.1.json')['cases'] as $case) {
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
            'mysql-recode-and-labels-preserve-dataset-and-table-identity',
            'dolt-recode-and-labels-preserve-controlled-context' => $this->assertOfficialBackendSuccess($case),
            'reject-dolt-branch-mismatch',
            'reject-dolt-head-mismatch',
            'reject-dolt-dirty-working-set' => $this->assertOfficialDoltContextFailure($case),
            'reject-mysql-nontransactional-create-target' => $this->assertOfficialMySqlCreateRejection($case),
            default => throw new RuntimeException('Unhandled official in-place 0.1 case: ' . $id),
        };
    }

    public function testSqliteNumericRecodeUsesFirstMatchInclusiveRangeAndSystemMissing(): void
    {
        $pdo = $this->fixture();
        $connection = new Connection($pdo);
        $plan = $this->planCase('numeric-recode-and-declared-labels');
        $request = new InPlaceApplyRequest(
            $plan,
            'parent',
            self::DATASET_ID,
            hash('sha256', 'RECODE q1.'),
            'conformance-runner',
        );
        $tablesBefore = $this->tables($pdo);

        $result = (new InPlaceTransformationExecutor($connection))->execute($request);

        self::assertSame('075571b4f84b0f60f289aebb6ec990ad61d34a96b46655802ea8f8bc07a1a405', $result->planHash());
        self::assertSame($tablesBefore, $this->tables($pdo));
        self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM dataset'));
        self::assertSame(
            [0.0, 0.0, 1.0, 1.0, 1.0, null, null],
            array_map(
                static fn(mixed $value): ?float => $value === null ? null : (float) $value,
                $this->column($pdo, 'SELECT q1_binary FROM data_plan01 ORDER BY __case_ordinal'),
            ),
        );
        self::assertSame('Positive response', $this->scalar(
            $pdo,
            "SELECT variable_label FROM variable WHERE dataset_id = '" . self::DATASET_ID . "' AND source_name = 'q1_binary'",
        ));
        self::assertSame(
            [[0.0, 'No'], [1.0, 'Yes']],
            array_map(
                static fn(array $row): array => [(float) $row['numeric_code'], (string) $row['label']],
                $this->rows($pdo, 'SELECT label.numeric_code, label.label FROM value_label label ORDER BY label.ordinal'),
            ),
        );
        self::assertSame(
            ['openstatspec-in-place-transformation-v0.1', $result->planHash(), 3],
            $this->normalizedAudit($pdo),
        );
    }

    public function testSqliteStringValueLabelsAreReplacedExactlyWithoutChangingOtherMetadata(): void
    {
        $pdo = $this->fixture();
        $pdo->exec("ALTER TABLE data_plan01 ADD COLUMN color TEXT NOT NULL DEFAULT ''");
        $pdo->prepare(
            'INSERT INTO variable (variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind, declared_string_width, variable_label, measurement_level) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute(['77777777-7777-4777-8777-777777777777', self::DATASET_ID, 2, 'color', 'color', 'string', 1, 'Colour', 'nominal']);
        $pdo->exec("UPDATE data_plan01 SET color = CASE WHEN __case_ordinal % 2 = 0 THEN 'B' ELSE 'R' END");
        $plan = $this->planCase('string-value-label-replacement');
        $request = new InPlaceApplyRequest($plan, 'parent', self::DATASET_ID, hash('sha256', 'VALUE LABELS color.'), 'conformance-runner');

        (new InPlaceTransformationExecutor(new Connection($pdo)))->execute($request);

        self::assertSame(
            [['R', 'Punane'], ['B', 'Sinine']],
            array_map(
                static fn(array $row): array => [(string) $row['string_code'], (string) $row['label']],
                $this->rows($pdo, 'SELECT label.string_code, label.label FROM value_label label ORDER BY label.ordinal'),
            ),
        );
        self::assertSame(
            ['variable_label' => 'Colour', 'measurement_level' => 'nominal'],
            $this->rows($pdo, "SELECT variable_label, measurement_level FROM variable WHERE source_name = 'color'")[0],
        );
    }

    private function fixture(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available.');
        }
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => false]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        (new NormativeCatalog($pdo))->createTables();
        (new TransformationAuditMigrator($pdo))->migrate();
        CatalogOwnership::markCurrentVersion($pdo);
        $pdo->exec('CREATE TABLE data_plan01 (__case_ordinal INTEGER NOT NULL PRIMARY KEY, q1 REAL NULL)');
        $pdo->prepare(
            'INSERT INTO dataset (dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, dataset_name, source_case_count, imported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([self::DATASET_ID, '1.0', 'fixture', null, 'data_plan01', 'Plan 0.1 fixture', 7, '2026-08-17 00:00:00']);
        $pdo->prepare(
            'INSERT INTO variable (variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind) VALUES (?, ?, ?, ?, ?, ?)',
        )->execute(['88888888-8888-4888-8888-888888888888', self::DATASET_ID, 1, 'q1', 'q1', 'numeric']);
        $insert = $pdo->prepare('INSERT INTO data_plan01 (__case_ordinal, q1) VALUES (?, ?)');
        foreach ([[1, 1.0], [2, 2.0], [3, 3.0], [4, 4.0], [5, 5.0], [6, 6.0], [7, null]] as $row) {
            $insert->execute($row);
        }
        return $pdo;
    }

    /** @param array<string, mixed> $case */
    private function assertOfficialBackendSuccess(array $case): void
    {
        $profile = $case['database_profile'] ?? null;
        $beforeContract = $case['before'] ?? null;
        $afterContract = $case['after'] ?? null;
        self::assertContains($profile, ['mysql', 'dolt']);
        self::assertIsArray($beforeContract);
        self::assertIsArray($afterContract);
        $database = $profile === 'mysql' ? (string) $beforeContract['physical_table_schema'] : null;
        $pdo = $this->isolatedBackendPdo((string) $profile, $database);
        $connection = new Connection($pdo);
        $fixture = $this->installOfficialBackendFixture($pdo, $connection, $case);
        $context = $this->backendContext($pdo, $connection);
        $before = $this->officialBackendSnapshot($pdo, $connection, $fixture);
        $compiled = $this->compileOfficialSource((string) $case['source_text']);
        $request = new InPlaceApplyRequest(
            $compiled->plan,
            'parent',
            $fixture['dataset_id'],
            $compiled->sourceHash,
            'conformance-runner',
            $context['branch'] ?? null,
            $context['head'] ?? null,
        );

        $result = (new InPlaceTransformationExecutor($connection))->execute($request);
        $after = $this->officialBackendSnapshot($pdo, $connection, $fixture);

        self::assertSame($beforeContract['dataset_id'], $result->datasetId());
        self::assertSame($before['dataset_count'], $after['dataset_count']);
        self::assertSame($before['persistent_data_table_count'], $after['persistent_data_table_count']);
        self::assertSame($before['dataset_identity'], $after['dataset_identity']);
        self::assertSame($before['tables'], $after['tables']);
        self::assertSame($before['case_count'], $after['case_count']);
        self::assertSame($before['case_ordinals'], $after['case_ordinals']);
        self::assertSame([0.0, 0.0, 1.0, 4.0], $after['score']);
        self::assertSame($afterContract['variable_label'], $after['variable_label']);
        self::assertSame($afterContract['value_labels'], $after['value_labels']);
        self::assertCount(1, $after['audit']);
        $audit = $after['audit'][0];
        foreach ($case['required_audit_fields'] as $field) {
            self::assertArrayHasKey($field, $audit);
        }
        self::assertSame('openstatspec-in-place-transformation-v0.1', $audit['contract_id']);
        self::assertSame($profile, $audit['database_profile']);
        self::assertSame($fixture['dataset_id'], $audit['dataset_id']);
        self::assertSame($fixture['schema'], $audit['physical_table_schema']);
        self::assertSame($fixture['table'], $audit['physical_table_name']);
        self::assertSame($compiled->sourceHash, $audit['source_hash']);
        self::assertSame($result->planHash(), $audit['plan_hash']);
        self::assertSame('conformance-runner', $audit['actor']);
        self::assertSame('succeeded', $audit['status']);
        self::assertSame($result->operationCount(), (int) $audit['operation_count']);
        $this->assertNoForbiddenArtifactTables($after['tables']);

        if ($profile === 'dolt') {
            if ($context === null) {
                throw new RuntimeException('Dolt conformance execution did not capture repository context.');
            }
            self::assertNotNull($before['repository']);
            self::assertNotNull($after['repository']);
            self::assertSame($before['repository']['branch'], $after['repository']['branch']);
            self::assertSame($before['repository']['head'], $after['repository']['head']);
            self::assertSame($before['repository']['history'], $after['repository']['history']);
            self::assertNotSame([], $after['repository']['status']);
            self::assertContains($fixture['table'], array_column($after['repository']['status'], 'table_name'));
            self::assertFalse((bool) $afterContract['dolt_commit_performed']);
            self::assertSame($context['branch'], $audit['dolt_branch']);
            self::assertSame($context['head'], $audit['dolt_head_before']);
            self::assertSame($context['head'], $audit['dolt_head_after']);
        } else {
            self::assertNull($audit['dolt_branch']);
            self::assertNull($audit['dolt_head_before']);
            self::assertNull($audit['dolt_head_after']);
        }
    }

    /** @param array<string, mixed> $case */
    private function assertOfficialDoltContextFailure(array $case): void
    {
        $pdo = $this->isolatedBackendPdo('dolt');
        $connection = new Connection($pdo);
        $fixture = $this->installOfficialBackendFixture($pdo, $connection, $case);
        $context = $this->backendContext($pdo, $connection);
        self::assertNotNull($context);
        $id = (string) $case['id'];
        if ($id === 'reject-dolt-dirty-working-set') {
            $pdo->exec(
                'UPDATE ' . $this->qualifiedBackendTable($connection, $fixture['schema'], $fixture['table'])
                . ' SET `score` = 99 WHERE `__case_ordinal` = 1',
            );
        }
        $before = $this->officialBackendSnapshot($pdo, $connection, $fixture);
        $source = $this->successfulSourceText();
        $compiled = $this->compileOfficialSource($source);
        $expectedBranch = $context['branch'];
        $expectedHead = $context['head'];
        if ($id === 'reject-dolt-branch-mismatch') {
            $expectedBranch = (string) $case['expected_context']['branch'];
        } elseif ($id === 'reject-dolt-head-mismatch') {
            $expectedHead = (string) $case['observed_context']['head'];
        }

        try {
            (new InPlaceTransformationExecutor($connection))->execute(new InPlaceApplyRequest(
                $compiled->plan,
                'parent',
                $fixture['dataset_id'],
                hash('sha256', $source),
                'conformance-runner',
                $expectedBranch,
                $expectedHead,
            ));
            self::fail('An official Dolt context failure was accepted: ' . $id);
        } catch (TransformationFailure $failure) {
            self::assertSame($case['expected_error'], $failure->diagnosticCode());
        }

        self::assertFalse($case['mutation_started']);
        self::assertFalse($pdo->inTransaction());
        self::assertSame($before, $this->officialBackendSnapshot($pdo, $connection, $fixture));
    }

    /** @param array<string, mixed> $case */
    private function assertOfficialMySqlCreateRejection(array $case): void
    {
        $pdo = $this->isolatedBackendPdo('mysql');
        $connection = new Connection($pdo);
        $fixture = $this->installOfficialBackendFixture($pdo, $connection, $case);
        $before = $this->officialBackendSnapshot($pdo, $connection, $fixture);
        $compiled = $this->compileOfficialSource((string) $case['source_text']);

        try {
            (new InPlaceTransformationExecutor($connection))->execute(new InPlaceApplyRequest(
                $compiled->plan,
                'parent',
                $fixture['dataset_id'],
                $compiled->sourceHash,
                'conformance-runner',
            ));
            self::fail('The official MySQL create-target rejection was accepted.');
        } catch (TransformationFailure $failure) {
            self::assertSame($case['expected_error'], $failure->diagnosticCode());
        }

        self::assertFalse($case['mutation_started']);
        self::assertFalse($pdo->inTransaction());
        self::assertSame($before, $this->officialBackendSnapshot($pdo, $connection, $fixture));
    }

    private function compileOfficialSource(string $source): \OpenStatSpec\Frontend\Spss\SpssCompilationResult
    {
        return (new SpssCompiler())->compile(new SpssFrontendRequest(
            SpssFrontendRequest::CONTRACT,
            'parent',
            new InputSchema([new InputVariable('score', 'numeric')]),
            $source,
        ));
    }

    private function successfulSourceText(): string
    {
        return (string) $this->bindingCase('mysql-recode-and-labels-preserve-dataset-and-table-identity')['source_text'];
    }

    private function isolatedBackendPdo(string $profile, ?string $preferredDatabase = null): PDO
    {
        $prefix = match ($profile) {
            'mysql' => 'OPENSTATSPEC_MYSQL',
            'dolt' => 'OPENSTATSPEC_DOLT',
            default => throw new RuntimeException('Unsupported official 0.1 backend profile.'),
        };
        $dsn = getenv($prefix . '_DSN');
        $adminUser = getenv($prefix . '_ADMIN_USER');
        $adminPassword = getenv($prefix . '_ADMIN_PASSWORD');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped($prefix . '_DSN is not configured.');
        }
        if (!is_string($adminUser) || $adminUser === '' || !is_string($adminPassword)) {
            self::markTestSkipped($prefix . ' explicit admin credentials are required for isolated official tests.');
        }
        $targetUser = getenv($prefix . '_USER');
        $targetPassword = getenv($prefix . '_PASSWORD');
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => false];
        $database = $preferredDatabase ?? sprintf(
            'openstatspec_t9_v01_%d_%s',
            getmypid(),
            bin2hex(random_bytes(6)),
        );
        $quotedDatabase = $this->quoteDatabase($database);
        $admin = new PDO($dsn, $adminUser, $adminPassword, $options);
        self::assertSame(0, (int) $this->scalar(
            $admin,
            'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?',
            [$database],
        ));
        $admin->exec('CREATE DATABASE ' . $quotedDatabase);
        $this->isolatedTestDatabases[] = ['admin' => $admin, 'database' => $database];
        if (is_string($targetUser) && $targetUser !== '') {
            $quotedTargetUser = $admin->quote($targetUser);
            if (!is_string($quotedTargetUser)) {
                throw new RuntimeException('Unable to quote the official backend test user.');
            }
            $admin->exec('GRANT ALL PRIVILEGES ON ' . $quotedDatabase . ".* TO {$quotedTargetUser}@'%'");
        }

        $pdo = new PDO(
            $this->dsnForDatabase($dsn, $database),
            is_string($targetUser) ? $targetUser : null,
            is_string($targetPassword) ? $targetPassword : null,
            $options,
        );
        self::assertSame($profile, (new Connection($pdo))->profileName);
        self::assertSame($database, $this->scalar($pdo, 'SELECT DATABASE()'));

        return $pdo;
    }

    /**
     * @param array<string, mixed> $case
     * @return array{dataset_id: string, schema: string|null, table: string}
     */
    private function installOfficialBackendFixture(PDO $pdo, Connection $connection, array $case): array
    {
        $before = is_array($case['before'] ?? null) ? $case['before'] : [];
        $observed = is_array($case['observed_context'] ?? null) ? $case['observed_context'] : [];
        if ($connection->profileName === 'dolt') {
            $branch = is_string($observed['branch'] ?? null)
                ? $observed['branch']
                : (is_string($before['dolt_branch'] ?? null) ? $before['dolt_branch'] : 'main');
            $active = (string) $this->scalar($pdo, 'SELECT active_branch()');
            if ($branch !== $active) {
                $checkout = $pdo->prepare('CALL DOLT_CHECKOUT(?, ?)');
                self::assertInstanceOf(PDOStatement::class, $checkout);
                $checkout->execute(['-b', $branch]);
            }
        }

        (new NormativeCatalog($pdo))->createTables();
        (new TransformationAuditMigrator($pdo))->migrate();
        CatalogOwnership::markCurrentVersion($pdo);
        $datasetId = is_string($before['dataset_id'] ?? null)
            ? $before['dataset_id']
            : '11111111-1111-4111-8111-111111111111';
        $schema = is_string($before['physical_table_schema'] ?? null) ? $before['physical_table_schema'] : null;
        $table = is_string($before['physical_table_name'] ?? null) ? $before['physical_table_name'] : 'data_survey';
        $qualified = $this->qualifiedBackendTable($connection, $schema, $table);
        $pdo->exec(
            'CREATE TABLE ' . $qualified . ' ('
            . $connection->profile->quoteIdentifier('__case_ordinal') . ' BIGINT NOT NULL PRIMARY KEY, '
            . $connection->profile->quoteIdentifier('score') . ' DOUBLE NULL)',
        );
        $pdo->prepare(
            'INSERT INTO dataset (dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, '
            . 'dataset_name, source_case_count, imported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$datasetId, '1.0', 'fixture', $schema, $table, 'Official in-place 0.1', 4, '2026-08-17 00:00:00']);
        $pdo->prepare(
            'INSERT INTO variable (variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
        )->execute(['22222222-2222-4222-8222-222222222222', $datasetId, 1, 'score', 'score', 'numeric']);
        $insert = $pdo->prepare(
            'INSERT INTO ' . $qualified . ' ('
            . $connection->profile->quoteIdentifier('__case_ordinal') . ', '
            . $connection->profile->quoteIdentifier('score') . ') VALUES (?, ?)',
        );
        foreach ([[1, 1.0], [2, 2.0], [3, 3.0], [4, 4.0]] as $row) {
            $insert->execute($row);
        }

        if ($connection->profileName === 'dolt') {
            $commit = $pdo->prepare('CALL DOLT_COMMIT(?, ?)');
            self::assertInstanceOf(PDOStatement::class, $commit);
            $commit->execute(['-Am', 'Official in-place 0.1 fixture']);
            self::assertSame([], $this->rows($pdo, 'SELECT table_name FROM dolt_status'));
        }

        return ['dataset_id' => $datasetId, 'schema' => $schema, 'table' => $table];
    }

    /** @return array{branch: string, head: string}|null */
    private function backendContext(PDO $pdo, Connection $connection): ?array
    {
        if ($connection->profileName !== 'dolt') {
            return null;
        }
        $row = $this->rows(
            $pdo,
            "SELECT active_branch() AS branch_name, dolt_hashof('HEAD') AS head_hash",
        )[0] ?? null;
        self::assertIsArray($row);

        return ['branch' => (string) $row['branch_name'], 'head' => (string) $row['head_hash']];
    }

    /**
     * @param array{dataset_id: string, schema: string|null, table: string} $fixture
     * @return array<string, mixed>
     */
    private function officialBackendSnapshot(PDO $pdo, Connection $connection, array $fixture): array
    {
        $qualified = $this->qualifiedBackendTable($connection, $fixture['schema'], $fixture['table']);
        $score = array_map(
            static fn(mixed $value): ?float => $value === null ? null : (float) $value,
            $this->column($pdo, 'SELECT score FROM ' . $qualified . ' ORDER BY __case_ordinal'),
        );
        $labels = array_map(
            static function (array $row): array {
                $numericCode = (float) $row['numeric_code'];

                return [
                    floor($numericCode) === $numericCode ? (int) $numericCode : $numericCode,
                    (string) $row['label'],
                ];
            },
            $this->rows(
                $pdo,
                'SELECT label.numeric_code, label.label FROM variable '
                . 'JOIN variable_value_label_set link ON link.variable_id = variable.variable_id '
                . 'JOIN value_label label ON label.value_label_set_id = link.value_label_set_id '
                . 'WHERE variable.dataset_id = ? AND variable.source_name = ? ORDER BY label.ordinal',
                [$fixture['dataset_id'], 'score'],
            ),
        );

        return [
            'dataset_count' => (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM dataset'),
            'persistent_data_table_count' => (int) $this->scalar($pdo, 'SELECT COUNT(DISTINCT physical_table_name) FROM dataset'),
            'dataset_identity' => $this->rows(
                $pdo,
                'SELECT dataset_id, physical_table_schema, physical_table_name FROM dataset WHERE dataset_id = ?',
                [$fixture['dataset_id']],
            ),
            'tables' => array_map('strval', $this->column(
                $pdo,
                'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name',
            )),
            'case_count' => (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM ' . $qualified),
            'case_ordinals' => array_map('intval', $this->column(
                $pdo,
                'SELECT __case_ordinal FROM ' . $qualified . ' ORDER BY __case_ordinal',
            )),
            'score' => $score,
            'variables' => $this->rows(
                $pdo,
                'SELECT * FROM variable WHERE dataset_id = ? ORDER BY source_ordinal',
                [$fixture['dataset_id']],
            ),
            'variable_label' => $this->scalar(
                $pdo,
                'SELECT variable_label FROM variable WHERE dataset_id = ? AND source_name = ?',
                [$fixture['dataset_id'], 'score'],
            ),
            'value_labels' => $labels,
            'audit' => $this->rows(
                $pdo,
                'SELECT * FROM transformation_apply WHERE dataset_id = ? ORDER BY apply_id',
                [$fixture['dataset_id']],
            ),
            'repository' => $this->backendRepositoryEvidence($pdo, $connection),
        ];
    }

    /** @return array{branch: string, head: string, history: list<string>, status: list<array{table_name: string, status: string, staged: bool}>}|null */
    private function backendRepositoryEvidence(PDO $pdo, Connection $connection): ?array
    {
        if ($connection->profileName !== 'dolt') {
            return null;
        }
        $identity = $this->rows(
            $pdo,
            "SELECT active_branch() AS branch_name, dolt_hashof('HEAD') AS head_hash",
        )[0] ?? [];
        $status = array_map(static function (array $row): array {
            $row = array_change_key_case($row, CASE_LOWER);

            return [
                'table_name' => (string) ($row['table_name'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'staged' => (bool) ($row['staged'] ?? false),
            ];
        }, $this->rows($pdo, 'SELECT table_name, status, staged FROM dolt_status ORDER BY table_name'));

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

    /** @param list<string> $tables */
    private function assertNoForbiddenArtifactTables(array $tables): void
    {
        self::assertSame([], array_values(array_filter(
            $tables,
            static fn(string $table): bool => preg_match('/derived|output|staging|snapshot|rollback|recovery/i', $table) === 1,
        )));
    }

    private function qualifiedBackendTable(Connection $connection, ?string $schema, string $table): string
    {
        return ($schema === null ? '' : $connection->profile->quoteIdentifier($schema) . '.')
            . $connection->profile->quoteIdentifier($table);
    }

    private function quoteDatabase(string $database): string
    {
        if (strlen($database) > 64 || preg_match('/\A[a-z][a-z0-9_]*\z/D', $database) !== 1) {
            throw new RuntimeException('Unsafe official backend database name.');
        }

        return '`' . $database . '`';
    }

    private function dsnForDatabase(string $dsn, string $database): string
    {
        $this->quoteDatabase($database);
        if (!str_starts_with(strtolower($dsn), 'mysql:')) {
            throw new RuntimeException('Official MySQL-family tests require a mysql PDO DSN.');
        }
        $parts = explode(';', substr($dsn, strlen('mysql:')));
        $databaseIndex = null;
        foreach ($parts as $index => $part) {
            if (strtolower(trim((string) explode('=', $part, 2)[0])) !== 'dbname') {
                continue;
            }
            if ($databaseIndex !== null) {
                throw new RuntimeException('Official backend DSN contains duplicate dbname settings.');
            }
            $databaseIndex = $index;
        }
        if ($databaseIndex === null) {
            if (end($parts) === '') {
                array_pop($parts);
            }
            $parts[] = 'dbname=' . $database;
        } else {
            $parts[$databaseIndex] = 'dbname=' . $database;
        }

        return 'mysql:' . implode(';', $parts);
    }

    private function planCase(string $id): TransformationPlan
    {
        foreach (SpecificationManifest::load('conformance/transformation-plan-0.1.json')['cases'] as $case) {
            if (is_array($case) && ($case['id'] ?? null) === $id && is_array($case['plan'] ?? null)) {
                return (new PlanCodec())->fromArray($case['plan']);
            }
        }
        throw new \RuntimeException('Missing plan case: ' . $id);
    }

    /** @return array<string, mixed> */
    private function bindingCase(string $id): array
    {
        foreach (SpecificationManifest::load('conformance/in-place-transformation-0.1.json')['cases'] as $case) {
            if (is_array($case) && ($case['id'] ?? null) === $id) {
                return $case;
            }
        }

        throw new \RuntimeException('Missing in-place 0.1 case: ' . $id);
    }

    /** @return list<string> */
    private function tables(PDO $pdo): array
    {
        return array_map('strval', $this->column($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"));
    }

    /** @return array{string, string, int} */
    private function normalizedAudit(PDO $pdo): array
    {
        $row = $this->rows($pdo, 'SELECT contract_id, plan_hash, operation_count FROM transformation_apply')[0];
        return [(string) $row['contract_id'], (string) $row['plan_hash'], (int) $row['operation_count']];
    }

}
