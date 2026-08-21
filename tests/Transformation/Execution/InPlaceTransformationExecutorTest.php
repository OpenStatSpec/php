<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Execution;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Sql\PostgreSqlProfile;
use OpenStatSpec\Transformation\Audit\TransformationAuditMigrator;
use OpenStatSpec\Transformation\Audit\TransformationAuditWriter;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Execution\BoundSchema;
use OpenStatSpec\Transformation\Execution\InPlaceApplyRequest;
use OpenStatSpec\Transformation\Execution\InPlaceTransformationExecutor;
use OpenStatSpec\Transformation\Execution\SqlPredicateCompiler;
use OpenStatSpec\Transformation\Plan\Expression\Comparison;
use OpenStatSpec\Transformation\Plan\Expression\LiteralOperand;
use OpenStatSpec\Transformation\Plan\Expression\VariableOperand;
use OpenStatSpec\Transformation\Plan\Operation\AssignOperation;
use OpenStatSpec\Transformation\Plan\Operation\ConditionalAssignOperation;
use OpenStatSpec\Transformation\Plan\Operation\RecodeOperation;
use OpenStatSpec\Transformation\Plan\Operation\ReplaceValueLabelsOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetFormatOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetVariableLabelOperation;
use OpenStatSpec\Transformation\Plan\Operation\ValueLabel;
use OpenStatSpec\Transformation\Plan\PlanContract;
use OpenStatSpec\Transformation\Plan\Recode\CopyResult;
use OpenStatSpec\Transformation\Plan\Recode\ExactMatch;
use OpenStatSpec\Transformation\Plan\Recode\LiteralResult;
use OpenStatSpec\Transformation\Plan\Recode\RecodeRule;
use OpenStatSpec\Transformation\Plan\TargetMode;
use OpenStatSpec\Transformation\Plan\TransformationPlan;
use OpenStatSpec\Transformation\Plan\Value\Binary64Value;
use OpenStatSpec\Transformation\Plan\Value\StringValue;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FaultInjectingSqlitePdo extends PDO
{
    public bool $failBegin = false;
    public bool $failCommit = false;
    public bool $failRollback = false;

    public function beginTransaction(): bool
    {
        return $this->failBegin ? false : parent::beginTransaction();
    }

    public function commit(): bool
    {
        return $this->failCommit ? false : parent::commit();
    }

    public function rollBack(): bool
    {
        return $this->failRollback ? false : parent::rollBack();
    }
}

final class InPlaceTransformationExecutorTest extends TestCase
{
    private const DATASET_ID = '018f47f2-8b6a-4c3d-8e1f-123456789abc';

    private FaultInjectingSqlitePdo $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available.');
        }
        $this->pdo = new FaultInjectingSqlitePdo('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        (new NormativeCatalog($this->pdo))->createTables();
        (new TransformationAuditMigrator($this->pdo))->migrate();
        CatalogOwnership::markCurrentVersion($this->pdo);
        $this->pdo->exec('CREATE TABLE "odd""table" (__case_ordinal INTEGER NOT NULL PRIMARY KEY, "odd""source" REAL NULL, "target"";DROP TABLE dataset;--" REAL NULL CHECK ("target"";DROP TABLE dataset;--" <> 12345.0), text_value TEXT COLLATE NOCASE NOT NULL)');
        $this->pdo->prepare(
            'INSERT INTO dataset (dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, dataset_name, source_case_count, imported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([self::DATASET_ID, '1.0', 'fixture', null, 'odd"table', 'Executor fixture', 2, '2026-08-17 00:00:00']);
        $insert = $this->pdo->prepare(
            'INSERT INTO variable (variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind, declared_string_width) VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $insert->execute(['11111111-1111-4111-8111-111111111111', self::DATASET_ID, 1, 'source', 'odd"source', 'numeric', null]);
        $insert->execute(['22222222-2222-4222-8222-222222222222', self::DATASET_ID, 2, 'target', 'target";DROP TABLE dataset;--', 'numeric', null]);
        $insert->execute(['33333333-3333-4333-8333-333333333333', self::DATASET_ID, 3, 'text', 'text_value', 'string', 8]);
        $insertCase = $this->pdo->prepare('INSERT INTO "odd""table" (__case_ordinal, "odd""source", "target"";DROP TABLE dataset;--", text_value) VALUES (?, ?, ?, ?)');
        $insertCase->execute([1, 2.0, 7.0, 'A']);
        $insertCase->execute([2, null, 7.0, 'B']);
    }

    public function testIdentifiersAndValuesAreBoundWithoutSqlInjection(): void
    {
        $label = "x'); DROP TABLE dataset; --";
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new VariableOperand('source')),
            new SetVariableLabelOperation('target', $label),
        ]);

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));

        self::assertSame([2.0, null], $this->numericColumn('target";DROP TABLE dataset;--'));
        self::assertSame($label, $this->scalar("SELECT variable_label FROM variable WHERE source_name = 'target'"));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM dataset'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM transformation_apply'));
    }

    public function testSuccessAuditCapturesStartedAtBeforeOperationsAndCompletedAtAfter(): void
    {
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new VariableOperand('source')),
        ]);
        $startedBefore = gmdate('Y-m-d H:i:s');

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));
        $row = $this->rows('SELECT started_at, completed_at FROM transformation_apply ORDER BY apply_id DESC LIMIT 1');
        self::assertCount(1, $row);

        $completedAfter = gmdate('Y-m-d H:i:s');
        self::assertGreaterThanOrEqual($startedBefore, $row[0]['started_at']);
        self::assertLessThanOrEqual($completedAfter, $row[0]['completed_at']);
        self::assertLessThanOrEqual($row[0]['started_at'], $row[0]['completed_at']);
    }

    public function testStringRecodeUsesExactBoundValuesDespiteColumnCollation(): void
    {
        $payload = "x');--";
        $this->pdo->exec("UPDATE \"odd\"\"table\" SET text_value = CASE __case_ordinal WHEN 1 THEN 'Match' ELSE 'match' END");
        $plan = new TransformationPlan(PlanContract::V01, 'parent', [
            new RecodeOperation('text', 'text', TargetMode::Replace, [
                new RecodeRule(new ExactMatch(new StringValue('Match')), new LiteralResult(new StringValue($payload))),
            ], new CopyResult()),
        ]);

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));

        self::assertSame([$payload, 'match'], $this->column('SELECT text_value FROM "odd""table" ORDER BY __case_ordinal'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM dataset'));
    }

    public function testBinary64LiteralAssignmentPreservesTheExactAdjacentValue(): void
    {
        $bits = '3ff0000000000001';
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new LiteralOperand(Binary64Value::fromBits($bits))),
        ]);

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));

        self::assertSame([$bits, $bits], array_map(
            static fn(mixed $value): string => bin2hex(pack('E', (float) $value)),
            $this->column('SELECT "target"";DROP TABLE dataset;--" FROM "odd""table" ORDER BY __case_ordinal'),
        ));
    }

    public function testLiteralOnlyNumericPredicateUsesNumericOrderingAndExactResultBits(): void
    {
        $bits = '3ff0000000000001';
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new ConditionalAssignOperation(
                new Comparison(
                    new LiteralOperand(Binary64Value::fromBits('4000000000000000')),
                    '<',
                    new LiteralOperand(Binary64Value::fromBits('4024000000000000')),
                ),
                'target',
                new LiteralOperand(Binary64Value::fromBits($bits)),
            ),
        ]);

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));

        self::assertSame([$bits, $bits], array_map(
            static fn(mixed $value): string => bin2hex(pack('E', (float) $value)),
            $this->column('SELECT "target"";DROP TABLE dataset;--" FROM "odd""table" ORDER BY __case_ordinal'),
        ));
    }

    public function testPostgresqlNumericLiteralsUseExactTextAndNumericCasts(): void
    {
        $parameters = [];
        $sql = (new SqlPredicateCompiler(new PostgreSqlProfile()))->compile(
            new Comparison(
                new LiteralOperand(Binary64Value::fromBits('3ff0000000000001')),
                '<',
                new LiteralOperand(Binary64Value::fromBits('4024000000000000')),
            ),
            new BoundSchema([]),
            $parameters,
        );

        self::assertSame('(CAST(? AS DOUBLE PRECISION) < CAST(? AS DOUBLE PRECISION))', $sql);
        self::assertSame(['1.0000000000000002', '10'], $parameters);
    }

    public function testRecodeMatchAndResultPreserveExactBinary64Values(): void
    {
        $this->pdo->exec('UPDATE "odd""table" SET "odd""source" = CAST(\'1.0000000000000002\' AS REAL) WHERE __case_ordinal = 1');
        $resultBits = '4000000000000001';
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new RecodeOperation('source', 'source', TargetMode::Replace, [
                new RecodeRule(
                    new ExactMatch(Binary64Value::fromBits('3ff0000000000001')),
                    new LiteralResult(Binary64Value::fromBits($resultBits)),
                ),
            ], new CopyResult()),
        ]);

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));

        $values = $this->column('SELECT "odd""source" FROM "odd""table" ORDER BY __case_ordinal');
        self::assertSame($resultBits, bin2hex(pack('E', (float) $values[0])));
        self::assertNull($values[1]);
    }

    public function testNumericValueLabelPreservesExactBinary64Code(): void
    {
        $bits = '3ff0000000000001';
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new ReplaceValueLabelsOperation('target', [
                new ValueLabel(Binary64Value::fromBits($bits), 'Adjacent'),
            ]),
        ]);

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));

        self::assertSame($bits, bin2hex(pack('E', (float) $this->scalar('SELECT numeric_code FROM value_label'))));
    }

    public function testStringRecodeLiteralWiderThanTargetFailsBeforeAnyMutation(): void
    {
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new LiteralOperand(Binary64Value::fromBits('3ff0000000000000'))),
            new RecodeOperation('text', 'text', TargetMode::Replace, [
                new RecodeRule(new ExactMatch(new StringValue('A')), new LiteralResult(new StringValue('123456789'))),
            ], new CopyResult()),
        ]);
        $before = $this->state();

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));
            self::fail('A recode literal wider than the target was applied.');
        } catch (TransformationFailure $failure) {
            self::assertSame('type_mismatch', $failure->diagnosticCode());
        }

        self::assertSame($before, $this->state());
    }

    public function testReservedPhysicalVariableBindingFailsBeforeMutation(): void
    {
        $this->pdo->exec("UPDATE variable SET physical_name = '__case_ordinal' WHERE source_name = 'source'");
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new VariableOperand('source')),
        ]);
        $before = $this->state();
        $failure = null;

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));
        } catch (TransformationFailure $caught) {
            $failure = $caught;
        }

        self::assertInstanceOf(TransformationFailure::class, $failure, 'A variable bound to the reserved case-order column was accepted.');
        self::assertSame('invalid_catalog', $failure->diagnosticCode());
        self::assertSame($before, $this->state());
    }

    public function testSharedPhysicalTableBindingFailsBeforeMutation(): void
    {
        $this->pdo->prepare(
            'INSERT INTO dataset (dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, dataset_name, source_case_count, imported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute(['99999999-9999-4999-8999-999999999999', '1.0', 'fixture', null, 'odd"table', 'Aliased table', 2, '2026-08-17 00:00:00']);
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new VariableOperand('source')),
        ]);
        $before = $this->state();

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));
            self::fail('Two datasets sharing one physical table were accepted.');
        } catch (TransformationFailure $failure) {
            self::assertSame('invalid_catalog', $failure->diagnosticCode());
        }

        self::assertSame($before, $this->state());
    }

    public function testSqliteNullAndMainSchemasCannotShareOnePhysicalTable(): void
    {
        $this->pdo->prepare(
            'INSERT INTO dataset (dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, dataset_name, source_case_count, imported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute(['99999999-9999-4999-8999-999999999999', '1.0', 'fixture', 'main', 'odd"table', 'Main-schema alias', 2, '2026-08-17 00:00:00']);
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new VariableOperand('source')),
        ]);
        $before = $this->state();
        $beforeDatasetCount = (int) $this->scalar('SELECT COUNT(*) FROM dataset');
        $beforePersistentTableCount = (int) $this->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
        $failure = null;

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));
        } catch (TransformationFailure $caught) {
            $failure = $caught;
        }

        self::assertInstanceOf(TransformationFailure::class, $failure, 'SQLite NULL and main schemas were treated as different physical namespaces.');
        self::assertSame('invalid_catalog', $failure->diagnosticCode());
        self::assertSame($before, $this->state());
        self::assertSame($beforeDatasetCount, (int) $this->scalar('SELECT COUNT(*) FROM dataset'));
        self::assertSame($beforePersistentTableCount, (int) $this->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"));
    }

    public function testSqliteQuotedTableCaseAliasesCannotShareOnePhysicalTable(): void
    {
        $this->pdo->prepare(
            'INSERT INTO dataset (dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, dataset_name, source_case_count, imported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute(['99999999-9999-4999-8999-999999999999', '1.0', 'fixture', 'main', 'ODD"TABLE', 'Case-folded alias', 2, '2026-08-17 00:00:00']);
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new VariableOperand('source')),
        ]);
        $before = $this->state();
        $beforeDatasetCount = (int) $this->scalar('SELECT COUNT(*) FROM dataset');
        $beforePersistentTableCount = (int) $this->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
        $failure = null;

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));
        } catch (TransformationFailure $caught) {
            $failure = $caught;
        }

        self::assertInstanceOf(TransformationFailure::class, $failure, 'SQLite quoted table case aliases were treated as different physical tables.');
        self::assertSame('invalid_catalog', $failure->diagnosticCode());
        self::assertSame($before, $this->state());
        self::assertSame($beforeDatasetCount, (int) $this->scalar('SELECT COUNT(*) FROM dataset'));
        self::assertSame($beforePersistentTableCount, (int) $this->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"));
    }

    public function testSqliteAttachedSchemaPhysicalColumnsAreIntrospectedInBoundNamespace(): void
    {
        $this->pdo->exec("ATTACH DATABASE ':memory:' AS other");
        $this->pdo->exec('CREATE TABLE other."odd""table" ("odd""source" REAL NULL, "target"";DROP TABLE dataset;--" REAL NULL, text_value TEXT)');
        $insert = $this->pdo->prepare('INSERT INTO other."odd""table" ("odd""source", "target"";DROP TABLE dataset;--", text_value) VALUES (?, ?, ?)');
        $insert->execute([2.0, 7.0, 'A']);
        $insert->execute([null, 7.0, 'B']);
        $this->pdo->exec("UPDATE dataset SET physical_table_schema = 'other'");
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new VariableOperand('source')),
        ]);

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));
            self::fail('An attached SQLite table without the case-order column was accepted.');
        } catch (TransformationFailure $failure) {
            self::assertSame('invalid_catalog', $failure->diagnosticCode());
        } finally {
            $this->pdo->exec('DETACH DATABASE other');
        }
    }

    public function testCrossDatasetValueLabelAssociationFailsBeforeEarlierUpdate(): void
    {
        $otherDataset = '99999999-9999-4999-8999-999999999999';
        $this->pdo->prepare(
            'INSERT INTO dataset (dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, dataset_name, source_case_count, imported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$otherDataset, '1.0', 'fixture', null, 'other_table', 'Other dataset', 0, '2026-08-17 00:00:00']);
        $this->pdo->prepare('INSERT INTO value_label_set (value_label_set_id, dataset_id, name) VALUES (?, ?, ?)')
            ->execute(['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $otherDataset, null]);
        $this->pdo->prepare('INSERT INTO variable_value_label_set (variable_id, value_label_set_id) VALUES (?, ?)')
            ->execute(['22222222-2222-4222-8222-222222222222', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new LiteralOperand($this->binary64(12345.0))),
            new ReplaceValueLabelsOperation('target', [
                new ValueLabel(Binary64Value::fromBits('3ff0000000000000'), 'One'),
            ]),
        ]);
        $before = $this->state();
        $failure = null;

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));
        } catch (TransformationFailure $caught) {
            $failure = $caught;
        }

        self::assertInstanceOf(TransformationFailure::class, $failure, 'A cross-dataset value-label association reached row mutation.');
        self::assertSame('invalid_catalog', $failure->diagnosticCode());
        self::assertSame($before, $this->state());
    }

    public function testFreshDatabasePreflightFailureIsReadOnly(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new LiteralOperand(Binary64Value::fromBits('3ff0000000000000'))),
        ]);

        try {
            (new InPlaceTransformationExecutor(new Connection($pdo)))->execute($this->request($plan));
            self::fail('Fresh-database preflight unexpectedly executed.');
        } catch (UnsupportedOperation $failure) {
            self::assertSame(DiagnosticCode::CatalogMigrationRequired, $failure->diagnosticCode);
        }

        $statement = $pdo->query("SELECT name FROM sqlite_master WHERE type IN ('table', 'view') ORDER BY name");
        self::assertInstanceOf(PDOStatement::class, $statement);
        self::assertSame([], $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return iterable<string, array{TransformationPlan, string}> */
    public static function invalidBindings(): iterable
    {
        yield 'unknown value variable' => [
            new TransformationPlan(PlanContract::V02, 'parent', [
                new AssignOperation('target', TargetMode::Replace, new VariableOperand('missing')),
            ]),
            'unknown_variable',
        ];
        yield 'create target already exists' => [
            new TransformationPlan(PlanContract::V02, 'parent', [
                new AssignOperation('target', TargetMode::Create, new LiteralOperand(Binary64Value::fromBits('3ff0000000000000'))),
            ]),
            'target_already_exists',
        ];
        yield 'replace target does not exist' => [
            new TransformationPlan(PlanContract::V02, 'parent', [
                new AssignOperation('missing', TargetMode::Replace, new LiteralOperand(Binary64Value::fromBits('3ff0000000000000'))),
            ]),
            'unknown_variable',
        ];
        yield 'conditional target does not exist' => [
            new TransformationPlan(PlanContract::V02, 'parent', [
                new ConditionalAssignOperation(
                    new Comparison(
                        new VariableOperand('source'),
                        '=',
                        new LiteralOperand(Binary64Value::fromBits('3ff0000000000000')),
                    ),
                    'missing',
                    new LiteralOperand(Binary64Value::fromBits('3ff0000000000000')),
                ),
            ]),
            'conditional_target_missing',
        ];
        yield 'string expression variable' => [
            new TransformationPlan(PlanContract::V02, 'parent', [
                new AssignOperation('target', TargetMode::Replace, new VariableOperand('text')),
            ]),
            'expression_type_unsupported',
        ];
        yield 'format target is string' => [
            new TransformationPlan(PlanContract::V02, 'parent', [new SetFormatOperation('text', 'F', 8, 2)]),
            'type_mismatch',
        ];
        yield 'invalid direct format object' => [
            new TransformationPlan(PlanContract::V02, 'parent', [new SetFormatOperation('target', 'F', 2, 2)]),
            'invalid_format',
        ];
        yield 'later invalid operation prevents earlier mutation' => [
            new TransformationPlan(PlanContract::V02, 'parent', [
                new AssignOperation('target', TargetMode::Replace, new LiteralOperand(Binary64Value::fromBits('3ff0000000000000'))),
                new SetVariableLabelOperation('missing', 'Never applied'),
            ]),
            'unknown_variable',
        ];
    }

    #[DataProvider('invalidBindings')]
    public function testCompleteBindingValidationRunsBeforeMutation(TransformationPlan $plan, string $code): void
    {
        $before = $this->state();

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));
            self::fail('Invalid plan unexpectedly mutated the dataset.');
        } catch (TransformationFailure $failure) {
            self::assertSame($code, $failure->diagnosticCode());
        }

        self::assertSame($before, $this->state());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testAuditSchemaMustBeReadyBeforeMutation(): void
    {
        $this->pdo->exec('DROP TABLE transformation_apply');
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new LiteralOperand(Binary64Value::fromBits('3ff0000000000000'))),
        ]);
        $before = $this->numericColumn('target";DROP TABLE dataset;--');

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));
            self::fail('Apply executed without the explicit audit schema.');
        } catch (UnsupportedOperation $failure) {
            self::assertSame(DiagnosticCode::CatalogMigrationRequired, $failure->diagnosticCode);
        }

        self::assertSame($before, $this->numericColumn('target";DROP TABLE dataset;--'));
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testCallerOwnedTransactionIsRejectedWithoutTouchingIt(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec('UPDATE "odd""table" SET "target"";DROP TABLE dataset;--" = 42 WHERE __case_ordinal = 1');
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new LiteralOperand(Binary64Value::fromBits('3ff0000000000000'))),
        ]);

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));
            self::fail('Apply unexpectedly joined the caller transaction.');
        } catch (UnsupportedOperation $failure) {
            self::assertSame(DiagnosticCode::UnsupportedOperation, $failure->diagnosticCode);
        }

        self::assertTrue($this->pdo->inTransaction());
        self::assertSame([42.0, 7.0], $this->numericColumn('target";DROP TABLE dataset;--'));
        $this->pdo->rollBack();
    }

    public function testCrossPdoAuditWriterIsRejectedBeforeApply(): void
    {
        $other = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $before = $this->state();

        try {
            new InPlaceTransformationExecutor(
                new Connection($this->pdo),
                auditWriter: new TransformationAuditWriter($other),
            );
            self::fail('An audit writer from another PDO connection was accepted.');
        } catch (\InvalidArgumentException $failure) {
            self::assertStringContainsString('same PDO', $failure->getMessage());
        }

        self::assertSame($before, $this->state());
    }

    public function testSilentStatementExecuteFailureRollsBackWithoutAudit(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new LiteralOperand($this->binary64(12345.0))),
        ]);
        $before = $this->state();

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));
            self::fail('A failed UPDATE returned a successful apply result.');
        } catch (PDOException $failure) {
            self::assertStringContainsString('execute', strtolower($failure->getMessage()));
        }

        self::assertSame($before, $this->state());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testSilentAuditExecuteFailureRollsBackDataAndPublishesNoSuccess(): void
    {
        $this->pdo->exec("ALTER TABLE transformation_apply ADD COLUMN task8_actor_guard INTEGER NOT NULL DEFAULT 0 CHECK (actor <> 'force-audit-failure')");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new LiteralOperand(Binary64Value::fromBits('3ff0000000000000'))),
        ]);
        $before = $this->state();

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute(new InPlaceApplyRequest(
                $plan,
                'parent',
                self::DATASET_ID,
                str_repeat('a', 64),
                'force-audit-failure',
            ));
            self::fail('A failed audit INSERT returned a successful apply result.');
        } catch (PDOException $failure) {
            self::assertStringContainsString('audit', strtolower($failure->getMessage()));
        }

        self::assertSame($before, $this->state());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testFalseBeginOutcomeFailsBeforeMutation(): void
    {
        $this->pdo->failBegin = true;
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new LiteralOperand(Binary64Value::fromBits('3ff0000000000000'))),
        ]);
        $before = $this->state();

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));
            self::fail('A false beginTransaction outcome was accepted.');
        } catch (PDOException $failure) {
            self::assertStringContainsString('start', strtolower($failure->getMessage()));
        }

        self::assertSame($before, $this->state());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testFalseCommitOutcomeRollsBackDataAndAudit(): void
    {
        $this->pdo->failCommit = true;
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new LiteralOperand(Binary64Value::fromBits('3ff0000000000000'))),
        ]);
        $before = $this->state();

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));
            self::fail('A false commit outcome returned a successful apply result.');
        } catch (PDOException $failure) {
            self::assertStringContainsString('commit', strtolower($failure->getMessage()));
        }

        self::assertSame($before, $this->state());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testFalseRollbackOutcomeIsSurfaced(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->pdo->failRollback = true;
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [
            new AssignOperation('target', TargetMode::Replace, new LiteralOperand($this->binary64(12345.0))),
        ]);
        $before = $this->state();

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($this->request($plan));
            self::fail('A false rollback outcome was ignored.');
        } catch (PDOException $failure) {
            self::assertStringContainsString('rollback', strtolower($failure->getMessage()));
        } finally {
            $this->pdo->failRollback = false;
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }

        self::assertSame($before, $this->state());
    }

    private function request(TransformationPlan $plan): InPlaceApplyRequest
    {
        return new InPlaceApplyRequest($plan, 'parent', self::DATASET_ID, str_repeat('a', 64), 'unit-test');
    }

    private function binary64(float $value): Binary64Value
    {
        return Binary64Value::fromBits(bin2hex(pack('E', $value)));
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        return [
            'rows' => $this->rows('SELECT * FROM "odd""table" ORDER BY __case_ordinal'),
            'variables' => $this->rows('SELECT * FROM variable ORDER BY source_ordinal'),
            'audit' => $this->rows('SELECT * FROM transformation_apply ORDER BY apply_id'),
            'tables' => $this->column("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"),
        ];
    }

    /** @return list<float|null> */
    private function numericColumn(string $column): array
    {
        $quoted = (new Connection($this->pdo))->profile->quoteIdentifier($column);
        return array_map(
            static fn(mixed $value): ?float => $value === null ? null : (float) $value,
            $this->column('SELECT ' . $quoted . ' FROM "odd""table" ORDER BY __case_ordinal'),
        );
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        return $statement->fetchColumn();
    }

    /** @return list<mixed> */
    private function column(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        return array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
