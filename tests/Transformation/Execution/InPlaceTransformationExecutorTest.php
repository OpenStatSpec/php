<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Execution;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Transformation\Audit\TransformationAuditMigrator;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Execution\InPlaceApplyRequest;
use OpenStatSpec\Transformation\Execution\InPlaceTransformationExecutor;
use OpenStatSpec\Transformation\Plan\Expression\Comparison;
use OpenStatSpec\Transformation\Plan\Expression\LiteralOperand;
use OpenStatSpec\Transformation\Plan\Expression\VariableOperand;
use OpenStatSpec\Transformation\Plan\Operation\AssignOperation;
use OpenStatSpec\Transformation\Plan\Operation\ConditionalAssignOperation;
use OpenStatSpec\Transformation\Plan\Operation\RecodeOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetFormatOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetVariableLabelOperation;
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
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InPlaceTransformationExecutorTest extends TestCase
{
    private const DATASET_ID = '018f47f2-8b6a-4c3d-8e1f-123456789abc';

    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available.');
        }
        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        (new NormativeCatalog($this->pdo))->createTables();
        (new TransformationAuditMigrator($this->pdo))->migrate();
        CatalogOwnership::markCurrentVersion($this->pdo);
        $this->pdo->exec('CREATE TABLE "odd""table" (__case_ordinal INTEGER NOT NULL PRIMARY KEY, "odd""source" REAL NULL, "target"";DROP TABLE dataset;--" REAL NULL, text_value TEXT COLLATE NOCASE NOT NULL)');
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

    public function testStringRecodeUsesExactBoundValuesDespiteColumnCollation(): void
    {
        $payload = "x'); DROP TABLE dataset; --";
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

    private function request(TransformationPlan $plan): InPlaceApplyRequest
    {
        return new InPlaceApplyRequest($plan, 'parent', self::DATASET_ID, str_repeat('a', 64), 'unit-test');
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
