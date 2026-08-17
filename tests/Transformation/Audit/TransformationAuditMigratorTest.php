<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Audit;

use OpenStatSpec\Spss\SpssAdapter;
use OpenStatSpec\Transformation\Audit\TransformationAuditMigrator;
use OpenStatSpec\Transformation\Audit\TransformationAuditWriter;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Execution\DatasetBinding;
use OpenStatSpec\Transformation\Execution\InPlaceApplyRequest;
use OpenStatSpec\Transformation\Plan\Operation\SetVariableLabelOperation;
use OpenStatSpec\Transformation\Plan\Operation\ExecuteOperation;
use OpenStatSpec\Transformation\Plan\PlanContract;
use OpenStatSpec\Transformation\Plan\TransformationPlan;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class TransformationAuditMigratorTest extends TestCase
{
    private const DATASET_ID = '11111111-1111-4111-8111-111111111111';

    public function testApplyRequestAcceptsOneExactAliasAndValidatedBinding(): void
    {
        $request = new InPlaceApplyRequest(
            plan: $this->plan(),
            inputAlias: 'parent',
            datasetId: self::DATASET_ID,
            sourceHash: str_repeat('a', 64),
            actor: 'conformance-runner',
        );

        self::assertSame('parent', $request->inputAlias);
        self::assertSame(self::DATASET_ID, $request->datasetId);
        self::assertSame(str_repeat('a', 64), $request->sourceHash);
        self::assertSame('conformance-runner', $request->actor);
        self::assertNull($request->expectedBranch);
        self::assertNull($request->expectedHead);
    }

    public function testApplyRequestRejectsInvalidBindingsBeforeExecution(): void
    {
        $plan = $this->plan();
        $hash = str_repeat('a', 64);
        foreach ([
            'alias mismatch' => [
                'unknown_input_alias',
                static fn() => new InPlaceApplyRequest($plan, 'other', self::DATASET_ID, $hash, 'conformance-runner'),
            ],
            'malformed dataset UUID' => [
                'invalid_dataset_id',
                static fn() => new InPlaceApplyRequest($plan, 'parent', 'not-a-uuid', $hash, 'conformance-runner'),
            ],
            'malformed source hash' => [
                'invalid_source_hash',
                static fn() => new InPlaceApplyRequest($plan, 'parent', self::DATASET_ID, str_repeat('A', 64), 'conformance-runner'),
            ],
            'empty actor' => [
                'actor_required',
                static fn() => new InPlaceApplyRequest($plan, 'parent', self::DATASET_ID, $hash, " \t"),
            ],
            'branch without head' => [
                'dolt_context_required',
                static fn() => new InPlaceApplyRequest($plan, 'parent', self::DATASET_ID, $hash, 'conformance-runner', 'feature/recode'),
            ],
            'head without branch' => [
                'dolt_context_required',
                static fn() => new InPlaceApplyRequest($plan, 'parent', self::DATASET_ID, $hash, 'conformance-runner', expectedHead: 'expected-head'),
            ],
            'empty branch' => [
                'dolt_context_required',
                static fn() => new InPlaceApplyRequest($plan, 'parent', self::DATASET_ID, $hash, 'conformance-runner', '', 'expected-head'),
            ],
            'empty head' => [
                'dolt_context_required',
                static fn() => new InPlaceApplyRequest($plan, 'parent', self::DATASET_ID, $hash, 'conformance-runner', 'feature/recode', ''),
            ],
        ] as $description => [$expectedCode, $factory]) {
            try {
                $factory();
                self::fail($description . ' was accepted.');
            } catch (TransformationFailure $failure) {
                self::assertSame($expectedCode, $failure->diagnosticCode(), $description);
            }
        }
    }

    public function testFreshSqliteMigrationCreatesTheExactCompactAuditTable(): void
    {
        $pdo = $this->sqlite();

        (new TransformationAuditMigrator($pdo))->migrate();
        (new TransformationAuditMigrator($pdo))->migrate();

        self::assertSame(
            ['openstatspec-in-place-transformation-v0.1', 'openstatspec-in-place-transformation-v0.2'],
            $this->acceptedContracts($pdo),
        );
        self::assertSame([
            'apply_id',
            'contract_id',
            'database_profile',
            'dataset_id',
            'physical_table_schema',
            'physical_table_name',
            'source_hash',
            'plan_hash',
            'canonical_plan_json',
            'actor',
            'status',
            'dolt_branch',
            'dolt_head_before',
            'dolt_head_after',
            'operation_count',
            'started_at',
            'completed_at',
        ], $this->columnNames($pdo));
        self::assertSame([1, 2, 3, 4], $this->migrationVersions($pdo));
        self::assertSame(
            ['transformation_apply'],
            $this->query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'transformation_apply%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testAdapterMigrationRecordsFreshVersionsOneThroughFourExactlyOnce(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $adapter = new SpssAdapter($pdo);

        $adapter->migrateCatalog();
        $adapter->migrateCatalog();

        self::assertSame([1, 2, 3, 4], $this->migrationVersions($pdo));
        self::assertSame(4, (int) $this->query($pdo, 'SELECT schema_version FROM catalog_identity')->fetchColumn());
        self::assertSame(
            ['transformation_apply'],
            $this->query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'transformation_apply'")->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testSqliteMigrationPreservesAnExistingVersion01AuditRowWithoutResidue(): void
    {
        $pdo = $this->sqlite();
        $this->createVersion01AuditTable($pdo);
        $this->insertVersion01AuditRow($pdo);
        $before = $this->query($pdo, 'SELECT * FROM transformation_apply')->fetch(PDO::FETCH_ASSOC);

        (new TransformationAuditMigrator($pdo))->migrate();

        self::assertSame($before, $this->query($pdo, 'SELECT * FROM transformation_apply')->fetch(PDO::FETCH_ASSOC));
        self::assertSame(
            ['openstatspec-in-place-transformation-v0.1', 'openstatspec-in-place-transformation-v0.2'],
            $this->acceptedContracts($pdo),
        );
        self::assertSame(
            ['transformation_apply'],
            $this->query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'transformation_apply%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN),
        );
        self::assertSame([1, 2, 3, 4], $this->migrationVersions($pdo));
    }

    public function testFailedSqliteRebuildRollsBackToTheVersion01Table(): void
    {
        $pdo = $this->sqlite(false);
        $this->createVersion01AuditTable($pdo);
        $this->insertVersion01AuditRow($pdo, '22222222-2222-4222-8222-222222222222');
        $pdo->exec('PRAGMA foreign_keys = ON');

        try {
            (new TransformationAuditMigrator($pdo))->migrate();
            self::fail('Migration accepted an audit row whose dataset foreign key is missing.');
        } catch (\Throwable) {
            // The unchanged v0.1 table below proves the native transaction rolled back.
        }

        self::assertSame(['openstatspec-in-place-transformation-v0.1'], $this->acceptedContracts($pdo));
        self::assertSame(1, (int) $this->query($pdo, 'SELECT COUNT(*) FROM transformation_apply')->fetchColumn());
        self::assertSame(
            ['transformation_apply'],
            $this->query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'transformation_apply%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN),
        );
        self::assertSame([1, 2, 3], $this->migrationVersions($pdo));
    }

    public function testSuccessWriterAddsOneCompactRowInsideTheCallerTransaction(): void
    {
        $pdo = $this->sqlite();
        (new TransformationAuditMigrator($pdo))->migrate();
        $request = new InPlaceApplyRequest(
            $this->plan(),
            'parent',
            self::DATASET_ID,
            str_repeat('a', 64),
            'conformance-runner',
        );
        $dataset = new DatasetBinding(self::DATASET_ID, 'Survey', null, 'data_survey');

        $pdo->beginTransaction();
        $applyId = (new TransformationAuditWriter($pdo))->succeed($request, $dataset, 'sqlite', null, null);
        $pdo->commit();

        $row = $this->query($pdo, 'SELECT * FROM transformation_apply')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame($applyId, $row['apply_id']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $applyId);
        self::assertSame('openstatspec-in-place-transformation-v0.1', $row['contract_id']);
        self::assertSame('sqlite', $row['database_profile']);
        self::assertSame(self::DATASET_ID, $row['dataset_id']);
        self::assertNull($row['physical_table_schema']);
        self::assertSame('data_survey', $row['physical_table_name']);
        self::assertSame(str_repeat('a', 64), $row['source_hash']);
        self::assertSame('73a4893b0bf20cd731786b6998526ba84affcdb39c034c561d1ad29548ac38fe', $row['plan_hash']);
        self::assertSame('{"contract":"openstatspec-transformation-plan-v0.1","input_alias":"parent","operations":[{"label":"Score","op":"set_variable_label","variable":"score"}]}', $row['canonical_plan_json']);
        self::assertSame('conformance-runner', $row['actor']);
        self::assertSame('succeeded', $row['status']);
        self::assertNull($row['dolt_branch']);
        self::assertNull($row['dolt_head_before']);
        self::assertNull($row['dolt_head_after']);
        self::assertSame(1, (int) $row['operation_count']);
        self::assertIsString($row['started_at']);
        self::assertIsString($row['completed_at']);
        self::assertSame(1, (int) $this->query($pdo, 'SELECT COUNT(*) FROM transformation_apply')->fetchColumn());
    }

    public function testSuccessWriterRequiresTheOpenApplyTransaction(): void
    {
        $pdo = $this->sqlite();
        (new TransformationAuditMigrator($pdo))->migrate();
        $request = new InPlaceApplyRequest(
            $this->plan(),
            'parent',
            self::DATASET_ID,
            str_repeat('a', 64),
            'conformance-runner',
        );

        $this->expectException(\LogicException::class);
        (new TransformationAuditWriter($pdo))->succeed(
            $request,
            new DatasetBinding(self::DATASET_ID, 'Survey', null, 'data_survey'),
            'sqlite',
            null,
            null,
        );
    }

    public function testSuccessWriterUsesTheVersion02BindingContractForAVersion02Plan(): void
    {
        $pdo = $this->sqlite();
        (new TransformationAuditMigrator($pdo))->migrate();
        $request = new InPlaceApplyRequest(
            new TransformationPlan(PlanContract::V02, 'parent', [new ExecuteOperation()]),
            'parent',
            self::DATASET_ID,
            str_repeat('a', 64),
            'conformance-runner',
        );

        $pdo->beginTransaction();
        (new TransformationAuditWriter($pdo))->succeed(
            $request,
            new DatasetBinding(self::DATASET_ID, 'Survey', null, 'data_survey'),
            'sqlite',
            null,
            null,
        );
        $pdo->commit();

        self::assertSame(
            'openstatspec-in-place-transformation-v0.2',
            $this->query($pdo, 'SELECT contract_id FROM transformation_apply')->fetchColumn(),
        );
    }

    private function plan(): TransformationPlan
    {
        return new TransformationPlan(
            PlanContract::V01,
            'parent',
            [new SetVariableLabelOperation('score', 'Score')],
        );
    }

    private function sqlite(bool $foreignKeys = true): PDO
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ' . ($foreignKeys ? 'ON' : 'OFF'));
        $pdo->exec('CREATE TABLE dataset (dataset_id VARCHAR(36) NOT NULL PRIMARY KEY, physical_table_schema TEXT NULL, physical_table_name TEXT NOT NULL)');
        $pdo->exec("INSERT INTO dataset VALUES ('" . self::DATASET_ID . "', NULL, 'data_survey')");
        $pdo->exec('CREATE TABLE openstatspec_schema_migration (version INTEGER NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL)');
        $insert = $pdo->prepare('INSERT INTO openstatspec_schema_migration VALUES (?, ?)');
        foreach ([1, 2, 3] as $version) {
            $insert->execute([$version, '2026-08-17 00:00:00']);
        }
        return $pdo;
    }

    private function createVersion01AuditTable(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE transformation_apply (
  apply_id VARCHAR(36) NOT NULL PRIMARY KEY,
  contract_id TEXT NOT NULL,
  database_profile TEXT NOT NULL,
  dataset_id VARCHAR(36) NOT NULL REFERENCES dataset(dataset_id),
  physical_table_schema TEXT NULL,
  physical_table_name TEXT NOT NULL,
  source_hash CHAR(64) NOT NULL,
  plan_hash CHAR(64) NOT NULL,
  canonical_plan_json TEXT NOT NULL,
  actor TEXT NOT NULL,
  status TEXT NOT NULL,
  dolt_branch TEXT NULL,
  dolt_head_before TEXT NULL,
  dolt_head_after TEXT NULL,
  operation_count INTEGER NOT NULL,
  started_at TIMESTAMP NOT NULL,
  completed_at TIMESTAMP NOT NULL,
  CHECK (contract_id = 'openstatspec-in-place-transformation-v0.1'),
  CHECK (database_profile IN ('sqlite', 'postgresql', 'mysql', 'mariadb', 'dolt')),
  CHECK (status IN ('succeeded', 'failed')),
  CHECK (operation_count > 0),
  CHECK ((database_profile <> 'dolt' AND dolt_branch IS NULL AND dolt_head_before IS NULL AND dolt_head_after IS NULL) OR (database_profile = 'dolt' AND dolt_branch IS NOT NULL AND dolt_head_before IS NOT NULL AND (status = 'failed' OR dolt_head_after = dolt_head_before)))
)
SQL);
    }

    private function insertVersion01AuditRow(PDO $pdo, string $datasetId = self::DATASET_ID): void
    {
        $pdo->prepare('INSERT INTO transformation_apply VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'openstatspec-in-place-transformation-v0.1',
            'sqlite',
            $datasetId,
            null,
            'data_survey',
            str_repeat('b', 64),
            str_repeat('c', 64),
            '{"contract":"openstatspec-transformation-plan-v0.1"}',
            'legacy-runner',
            'succeeded',
            null,
            null,
            null,
            1,
            '2026-08-17 00:00:00',
            '2026-08-17 00:00:01',
        ]);
    }

    /** @return list<string> */
    private function acceptedContracts(PDO $pdo): array
    {
        $sql = $this->query($pdo, "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'transformation_apply'")->fetchColumn();
        self::assertIsString($sql);
        return array_values(array_filter(
            ['openstatspec-in-place-transformation-v0.1', 'openstatspec-in-place-transformation-v0.2'],
            static fn(string $contract): bool => str_contains($sql, $contract),
        ));
    }

    /** @return list<string> */
    private function columnNames(PDO $pdo): array
    {
        $columns = $this->query($pdo, 'PRAGMA table_info(transformation_apply)')->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_map(static fn(array $column): string => (string) $column['name'], $columns));
    }

    /** @return list<int> */
    private function migrationVersions(PDO $pdo): array
    {
        $versions = $this->query($pdo, 'SELECT version FROM openstatspec_schema_migration ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_map(static fn(mixed $version): int => (int) $version, $versions));
    }

    private function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            self::fail('Could not execute audit test query.');
        }
        return $statement;
    }
}
