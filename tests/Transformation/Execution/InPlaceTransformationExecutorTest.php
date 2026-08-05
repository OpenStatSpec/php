<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Execution;

use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Transformation\Execution\InPlaceTransformationExecutor;
use OpenStatSpec\Transformation\Model\Action\AssignValueAction;
use OpenStatSpec\Transformation\Model\Action\CopySourceAction;
use OpenStatSpec\Transformation\Model\Action\SetMissingAction;
use OpenStatSpec\Transformation\Model\CreateVariableOperation;
use OpenStatSpec\Transformation\Model\DeleteVariableOperation;
use OpenStatSpec\Transformation\Model\RecodeOperation;
use OpenStatSpec\Transformation\Model\RecodeRule;
use OpenStatSpec\Transformation\Model\ScalarValue;
use OpenStatSpec\Transformation\Model\Selector\ElseSelector;
use OpenStatSpec\Transformation\Model\Selector\ExactValueSelector;
use OpenStatSpec\Transformation\Model\Selector\MissingValueSelector;
use OpenStatSpec\Transformation\Model\Selector\NumericRangeSelector;
use OpenStatSpec\Transformation\Model\SetValueLabelsOperation;
use OpenStatSpec\Transformation\Model\SetVariableLabelOperation;
use OpenStatSpec\Transformation\Model\TransformationPlan;
use OpenStatSpec\Transformation\Model\ValueLabel;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class InPlaceTransformationExecutorTest extends TestCase
{
    private const DATASET_ID = '018f47f2-8b6a-7c3d-9e1f-123456789abc';

    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }
        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        (new NormativeCatalog($this->pdo))->createTables();
        $this->pdo->exec(
            'CREATE TABLE respondents (__case_ordinal INTEGER NOT NULL PRIMARY KEY, source_value REAL NULL, destination REAL NULL)',
        );
        $this->pdo->prepare(
            'INSERT INTO dataset '
            . '(dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, dataset_name, source_case_count, imported_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([self::DATASET_ID, '1.0', 'fixture', null, 'respondents', 'survey', 5, '2026-07-31 00:00:00']);
        $insertVariable = $this->pdo->prepare(
            'INSERT INTO variable '
            . '(variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
        );
        $insertVariable->execute(['018f47f2-8b6a-7c3d-9e1f-123456789abd', self::DATASET_ID, 1, 'SourceValue', 'source_value', 'numeric']);
        $insertVariable->execute(['018f47f2-8b6a-7c3d-9e1f-123456789abe', self::DATASET_ID, 2, 'Destination', 'destination', 'numeric']);
        $insertCase = $this->pdo->prepare(
            'INSERT INTO respondents (__case_ordinal, source_value, destination) VALUES (?, ?, ?)',
        );
        foreach ([[1, 1.0, -1.0], [2, 2.0, -1.0], [3, 3.0, -1.0], [4, 9.0, -1.0], [5, null, -1.0]] as $case) {
            $insertCase->execute($case);
        }
        CatalogOwnership::markCurrentVersion($this->pdo);
    }

    public function testCanonicalOperationsMutateDataAndMetadataInPlace(): void
    {
        $tablesBefore = $this->tableNames();
        $plan = new TransformationPlan(self::DATASET_ID, [
            new RecodeOperation('SourceValue', 'Destination', [
                new RecodeRule(
                    new ExactValueSelector(ScalarValue::number(1)),
                    new AssignValueAction(ScalarValue::number(10)),
                ),
                new RecodeRule(
                    new NumericRangeSelector(2.0, 3.0),
                    new AssignValueAction(ScalarValue::number(20)),
                ),
                new RecodeRule(
                    new MissingValueSelector(),
                    new AssignValueAction(ScalarValue::number(99)),
                ),
                new RecodeRule(new ElseSelector(), new CopySourceAction()),
            ]),
            new SetVariableLabelOperation('Destination', 'Recoded destination'),
            new SetValueLabelsOperation('Destination', [
                new ValueLabel(ScalarValue::number(10), 'Ten'),
                new ValueLabel(ScalarValue::number(20), 'Twenty'),
                new ValueLabel(ScalarValue::number(99), 'Missing source'),
            ]),
        ]);

        $result = (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($plan);

        self::assertSame(self::DATASET_ID, $result->datasetId());
        self::assertSame($plan->hash(), $result->planHash());
        self::assertSame(3, $result->operationCount());
        self::assertNull($result->auditOperationId(), 'Execution must not create a missing journal schema.');
        self::assertSame([10.0, 20.0, 20.0, 9.0, 99.0], $this->query(
            'SELECT destination FROM respondents ORDER BY __case_ordinal',
        )->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame('Recoded destination', $this->query(
            "SELECT variable_label FROM variable WHERE source_name = 'Destination'",
        )->fetchColumn());
        self::assertSame(
            [['10.0', 'Ten'], ['20.0', 'Twenty'], ['99.0', 'Missing source']],
            $this->query(
                'SELECT CAST(vl.numeric_code AS TEXT), vl.label FROM value_label vl ORDER BY vl.ordinal',
            )->fetchAll(PDO::FETCH_NUM),
        );
        self::assertSame($tablesBefore, $this->tableNames());
        self::assertSame(1, (int) $this->query('SELECT COUNT(*) FROM dataset')->fetchColumn());
        self::assertFalse(in_array('operation_catalog', $this->tableNames(), true));
    }

    public function testSqliteAddsANewTargetInsideTheSameWideTableAndTransaction(): void
    {
        $tablesBefore = $this->tableNames();
        $plan = new TransformationPlan(self::DATASET_ID, [
            new RecodeOperation('SourceValue', 'CreatedTarget', [
                new RecodeRule(
                    new ExactValueSelector(ScalarValue::number(1)),
                    new AssignValueAction(ScalarValue::number(100)),
                ),
                new RecodeRule(new ElseSelector(), new CopySourceAction()),
            ]),
        ]);

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($plan);

        self::assertSame($tablesBefore, $this->tableNames(), 'A transformation must not create a table or snapshot.');
        self::assertSame(1, (int) $this->query('SELECT COUNT(*) FROM dataset')->fetchColumn());
        self::assertSame(3, (int) $this->query('SELECT COUNT(*) FROM variable')->fetchColumn());
        self::assertSame('createdtarget', $this->query(
            "SELECT physical_name FROM variable WHERE source_name = 'CreatedTarget'",
        )->fetchColumn());
        self::assertSame(
            ['5', 8, 0, '5', 8, 0],
            $this->query(
                "SELECT print_format_family, print_format_width, print_format_decimals, "
                . "write_format_family, write_format_width, write_format_decimals "
                . "FROM variable WHERE source_name = 'CreatedTarget'",
            )->fetch(PDO::FETCH_NUM),
        );
        self::assertSame([100.0, 2.0, 3.0, 9.0, null], $this->query(
            'SELECT createdtarget FROM respondents ORDER BY __case_ordinal',
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testImplicitRecodeTargetRemainsActiveForLaterMetadataOperation(): void
    {
        $plan = new TransformationPlan(self::DATASET_ID, [
            new RecodeOperation('SourceValue', 'CreatedTarget', [
                new RecodeRule(new ElseSelector(), new CopySourceAction()),
            ]),
            new SetVariableLabelOperation('CreatedTarget', 'Created target'),
        ]);

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($plan);

        self::assertSame('Created target', $this->query(
            "SELECT variable_label FROM variable WHERE source_name = 'CreatedTarget'",
        )->fetchColumn());
    }

    public function testExplicitStringCreateAndDeleteRemovesDataAndMetadata(): void
    {
        $plan = new TransformationPlan(self::DATASET_ID, [
            new CreateVariableOperation('TempText', 'string', 20),
            new SetVariableLabelOperation('TempText', 'Temporary text'),
            new SetValueLabelsOperation('TempText', [
                new ValueLabel(ScalarValue::string('yes'), 'Yes'),
            ]),
            new DeleteVariableOperation('TempText'),
        ]);

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($plan);

        self::assertSame(2, (int) $this->query('SELECT COUNT(*) FROM variable')->fetchColumn());
        self::assertFalse(in_array('temptext', $this->tableColumns(), true));
        self::assertSame(0, (int) $this->query(
            'SELECT COUNT(*) FROM variable_value_label_set',
        )->fetchColumn());
        self::assertSame(0, (int) $this->query(
            'SELECT COUNT(*) FROM value_label',
        )->fetchColumn());
    }


    public function testDeleteThenCreateCanReuseVariableName(): void
    {
        $plan = new TransformationPlan(self::DATASET_ID, [
            new DeleteVariableOperation('SourceValue'),
            new CreateVariableOperation('SourceValue', 'string', 20),
        ]);

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($plan);

        $physicalName = (string) $this->query(
            "SELECT physical_name FROM variable WHERE source_name = 'SourceValue'",
        )->fetchColumn();
        self::assertNotSame('source_value', $physicalName);
        self::assertSame('string', $this->query(
            "SELECT storage_kind FROM variable WHERE source_name = 'SourceValue'",
        )->fetchColumn());
        self::assertTrue(in_array($physicalName, $this->tableColumns(), true));
        self::assertFalse(in_array('source_value', $this->tableColumns(), true));
    }

    public function testDeletingLastMultipleResponseMemberRemovesSet(): void
    {
        $this->pdo->prepare('INSERT INTO multiple_response_set (multiple_response_set_id, dataset_id, source_ordinal, set_name, set_kind) VALUES (?, ?, ?, ?, ?)')->execute(['mr-source', self::DATASET_ID, 1, '$MR', 'MC']);
        $this->pdo->prepare('INSERT INTO multiple_response_member (multiple_response_set_id, variable_id, source_ordinal) VALUES (?, ?, ?)')->execute(['mr-source', '018f47f2-8b6a-7c3d-9e1f-123456789abd', 1]);

        $plan = new TransformationPlan(self::DATASET_ID, [
            new DeleteVariableOperation('SourceValue'),
        ]);

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($plan);

        self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM multiple_response_set')->fetchColumn());
        self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM multiple_response_member')->fetchColumn());
    }

    public function testExplicitStringCreateInitializesBlankValuesAndAFormats(): void
    {
        $plan = new TransformationPlan(self::DATASET_ID, [
            new CreateVariableOperation('TempText', 'string', 20),
        ]);

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($plan);

        self::assertSame(['', '', '', '', ''], $this->query(
            'SELECT temptext FROM respondents ORDER BY __case_ordinal',
        )->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(0, (int) $this->query(
            'SELECT COUNT(*) FROM respondents WHERE temptext IS NULL',
        )->fetchColumn());
        self::assertSame(
            ['1', 20, 0, '1', 20, 0],
            $this->query(
                "SELECT print_format_family, print_format_width, print_format_decimals, "
                . "write_format_family, write_format_width, write_format_decimals "
                . "FROM variable WHERE source_name = 'TempText'",
            )->fetch(PDO::FETCH_NUM),
        );
    }

    public function testRecodeTargetCanReuseSlotFreedByEarlierDelete(): void
    {
        $connection = new Connection($this->pdo);
        $maximum = $connection->profile->effectiveMaximumSourceVariables($this->pdo);
        $columns = ['__case_ordinal INTEGER NOT NULL PRIMARY KEY'];
        for ($ordinal = 1; $ordinal <= $maximum; ++$ordinal) {
            $columns[] = 'v' . $ordinal . ' REAL NULL';
        }

        $this->pdo->exec('DROP TABLE respondents');
        $this->pdo->exec('CREATE TABLE respondents (' . implode(', ', $columns) . ')');
        $this->pdo->exec('DELETE FROM variable');
        $insert = $this->pdo->prepare(
            'INSERT INTO variable '
            . '(variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
        );
        $this->pdo->beginTransaction();
        for ($ordinal = 1; $ordinal <= $maximum; ++$ordinal) {
            $name = 'V' . $ordinal;
            $insert->execute([
                sprintf('00000000-0000-4000-8000-%012d', $ordinal),
                self::DATASET_ID,
                $ordinal,
                $name,
                strtolower($name),
                'numeric',
            ]);
        }
        $this->pdo->commit();

        $plan = new TransformationPlan(self::DATASET_ID, [
            new DeleteVariableOperation('V' . $maximum),
            new RecodeOperation('V1', 'Replacement', [
                new RecodeRule(new ElseSelector(), new CopySourceAction()),
            ]),
        ]);

        (new InPlaceTransformationExecutor($connection))->execute($plan);

        self::assertSame($maximum, (int) $this->query('SELECT COUNT(*) FROM variable')->fetchColumn());
        self::assertSame($maximum, count($this->query('PRAGMA table_info(respondents)')->fetchAll()) - 1);
        self::assertFalse(in_array('v' . $maximum, $this->tableColumns(), true));
        self::assertTrue(in_array('replacement', $this->tableColumns(), true));
    }

    public function testExplicitLongStringCreateCapsAFormatWidthsButKeepsStorageWidth(): void
    {
        $plan = new TransformationPlan(self::DATASET_ID, [
            new CreateVariableOperation('LongText', 'string', 400),
        ]);

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($plan);

        self::assertSame(
            [400, '1', 255, '1', 255],
            $this->query(
                "SELECT declared_string_width, print_format_family, print_format_width, "
                . "write_format_family, write_format_width "
                . "FROM variable WHERE source_name = 'LongText'",
            )->fetch(PDO::FETCH_NUM),
        );
    }

    public function testNewTargetIsRejectedBeforeAlterAtTheEffectiveColumnLimit(): void
    {
        $connection = new Connection($this->pdo);
        $maximum = $connection->profile->effectiveMaximumSourceVariables($this->pdo);
        $columns = ['__case_ordinal INTEGER NOT NULL PRIMARY KEY'];
        for ($ordinal = 1; $ordinal <= $maximum; ++$ordinal) {
            $columns[] = 'v' . $ordinal . ' REAL NULL';
        }

        $this->pdo->exec('DROP TABLE respondents');
        $this->pdo->exec('CREATE TABLE respondents (' . implode(', ', $columns) . ')');
        $this->pdo->exec('DELETE FROM variable');
        $insert = $this->pdo->prepare(
            'INSERT INTO variable '
            . '(variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
        );
        $this->pdo->beginTransaction();
        for ($ordinal = 1; $ordinal <= $maximum; ++$ordinal) {
            $name = 'V' . $ordinal;
            $insert->execute([
                sprintf('00000000-0000-4000-8000-%012d', $ordinal),
                self::DATASET_ID,
                $ordinal,
                $name,
                strtolower($name),
                'numeric',
            ]);
        }
        $this->pdo->commit();
        $columnsBefore = count($this->query('PRAGMA table_info(respondents)')->fetchAll());

        $plan = new TransformationPlan(self::DATASET_ID, [
            new RecodeOperation('V1', 'OverflowTarget', [
                new RecodeRule(new ElseSelector(), new CopySourceAction()),
            ]),
        ]);

        try {
            (new InPlaceTransformationExecutor($connection))->execute($plan);
            self::fail('A target beyond the effective source-variable limit was created.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
            self::assertStringContainsString('at most ' . $maximum . ' source variables', $exception->getMessage());
        }

        self::assertSame($maximum, (int) $this->query('SELECT COUNT(*) FROM variable')->fetchColumn());
        self::assertSame($columnsBefore, count($this->query('PRAGMA table_info(respondents)')->fetchAll()));
    }

    public function testRecodeWithOnlyElseAssignsTheExpressionDirectly(): void
    {
        $plan = new TransformationPlan(self::DATASET_ID, [
            new RecodeOperation('SourceValue', 'Destination', [
                new RecodeRule(
                    new ElseSelector(),
                    new AssignValueAction(ScalarValue::number(7)),
                ),
            ]),
        ]);

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($plan);

        self::assertSame(
            [7.0, 7.0, 7.0, 7.0, 7.0],
            $this->query('SELECT destination FROM respondents ORDER BY __case_ordinal')
                ->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testCallerOwnedTransactionIsRejectedWithoutRollbackOrJournalWork(): void
    {
        $tablesBefore = $this->tableNames();
        $this->pdo->beginTransaction();
        $this->pdo->exec('UPDATE respondents SET source_value = 42 WHERE __case_ordinal = 1');
        $plan = new TransformationPlan(self::DATASET_ID, [
            new RecodeOperation('SourceValue', 'Destination', [
                new RecodeRule(new ElseSelector(), new AssignValueAction(ScalarValue::number(7))),
            ]),
        ]);

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($plan);
            self::fail('Execution unexpectedly joined a caller-owned transaction.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::UnsupportedOperation, $exception->diagnosticCode);
            self::assertStringContainsString('caller-owned active transaction', $exception->getMessage());
        }

        self::assertTrue($this->pdo->inTransaction());
        self::assertSame(42.0, $this->query(
            'SELECT source_value FROM respondents WHERE __case_ordinal = 1',
        )->fetchColumn());
        self::assertSame($tablesBefore, $this->tableNames());
        self::assertFalse(in_array('operation_catalog', $this->tableNames(), true));
        self::assertSame(
            [-1.0, -1.0, -1.0, -1.0, -1.0],
            $this->query('SELECT destination FROM respondents ORDER BY __case_ordinal')
                ->fetchAll(PDO::FETCH_COLUMN),
        );

        self::assertTrue($this->pdo->commit());
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(42.0, $this->query(
            'SELECT source_value FROM respondents WHERE __case_ordinal = 1',
        )->fetchColumn());
    }

    public function testSystemMissingActionForStringTargetFailsBeforeMutation(): void
    {
        $this->pdo->exec("ALTER TABLE respondents ADD COLUMN source_text TEXT NOT NULL DEFAULT ''");
        $this->pdo->exec("ALTER TABLE respondents ADD COLUMN destination_text TEXT NOT NULL DEFAULT 'original'");
        $insertVariable = $this->pdo->prepare(
            'INSERT INTO variable '
            . '(variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind, declared_string_width) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $insertVariable->execute([
            '018f47f2-8b6a-7c3d-9e1f-123456789abf',
            self::DATASET_ID,
            3,
            'SourceText',
            'source_text',
            'string',
            8,
        ]);
        $insertVariable->execute([
            '018f47f2-8b6a-7c3d-9e1f-123456789ac0',
            self::DATASET_ID,
            4,
            'DestinationText',
            'destination_text',
            'string',
            8,
        ]);
        $plan = new TransformationPlan(self::DATASET_ID, [
            new RecodeOperation('SourceText', 'DestinationText', [
                new RecodeRule(new ElseSelector(), new SetMissingAction()),
            ]),
        ]);

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($plan);
            self::fail('System-missing recoding into a string target unexpectedly executed.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::InvalidSourceDataset, $exception->diagnosticCode);
            self::assertStringContainsString('not representable', $exception->getMessage());
        }

        self::assertSame(
            ['original', 'original', 'original', 'original', 'original'],
            $this->query('SELECT destination_text FROM respondents ORDER BY __case_ordinal')
                ->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testExactStringSelectorIgnoresCaseInsensitiveColumnCollation(): void
    {
        $this->pdo->exec('ALTER TABLE respondents ADD COLUMN source_text TEXT COLLATE NOCASE NULL');
        $this->pdo->exec('ALTER TABLE respondents ADD COLUMN destination_text TEXT NULL');
        $insertVariable = $this->pdo->prepare(
            'INSERT INTO variable '
            . '(variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind, declared_string_width) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $insertVariable->execute([
            '018f47f2-8b6a-7c3d-9e1f-123456789abf', self::DATASET_ID, 3,
            'SourceText', 'source_text', 'string', 8,
        ]);
        $insertVariable->execute([
            '018f47f2-8b6a-7c3d-9e1f-123456789ac0', self::DATASET_ID, 4,
            'DestinationText', 'destination_text', 'string', 8,
        ]);
        $this->pdo->exec("UPDATE respondents SET source_text = CASE __case_ordinal WHEN 1 THEN 'Match' WHEN 2 THEN 'match' ELSE 'other' END");
        $plan = new TransformationPlan(self::DATASET_ID, [
            new RecodeOperation('SourceText', 'DestinationText', [
                new RecodeRule(
                    new ExactValueSelector(ScalarValue::string('Match')),
                    new AssignValueAction(ScalarValue::string('exact')),
                ),
                new RecodeRule(new ElseSelector(), new AssignValueAction(ScalarValue::string('else'))),
            ]),
        ]);

        (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($plan);

        self::assertSame(
            ['exact', 'else', 'else', 'else', 'else'],
            $this->query('SELECT destination_text FROM respondents ORDER BY __case_ordinal')
                ->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testNewStringTargetRequiresExplicitCatalogWidth(): void
    {
        $this->pdo->exec('ALTER TABLE respondents ADD COLUMN source_text TEXT NULL');
        $this->pdo->prepare(
            'INSERT INTO variable '
            . '(variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind, declared_string_width) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            '018f47f2-8b6a-7c3d-9e1f-123456789abf',
            self::DATASET_ID,
            3,
            'SourceText',
            'source_text',
            'string',
            8,
        ]);
        $tablesBefore = $this->tableNames();
        $variablesBefore = (int) $this->query('SELECT COUNT(*) FROM variable')->fetchColumn();
        $plan = new TransformationPlan(self::DATASET_ID, [
            new RecodeOperation('SourceText', 'CreatedText', [
                new RecodeRule(
                    new ExactValueSelector(ScalarValue::string('a')),
                    new AssignValueAction(ScalarValue::string('b')),
                ),
                new RecodeRule(new ElseSelector(), new CopySourceAction()),
            ]),
        ]);

        try {
            (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($plan);
            self::fail('A string target without declared_string_width unexpectedly executed.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
        }

        self::assertSame($tablesBefore, $this->tableNames());
        self::assertSame($variablesBefore, (int) $this->query('SELECT COUNT(*) FROM variable')->fetchColumn());
        self::assertFalse(in_array('createdtext', $this->tableColumns(), true));
    }

    public function testExistingStringTargetEnforcesNormativeByteWidthBeforeMutation(): void
    {
        $this->pdo->exec('ALTER TABLE respondents ADD COLUMN source_text TEXT NULL');
        $this->pdo->exec('ALTER TABLE respondents ADD COLUMN destination_text TEXT NULL');
        $insertVariable = $this->pdo->prepare(
            'INSERT INTO variable '
            . '(variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind, declared_string_width) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $insertVariable->execute([
            '018f47f2-8b6a-7c3d-9e1f-123456789abf',
            self::DATASET_ID,
            3,
            'SourceText',
            'source_text',
            'string',
            4,
        ]);
        $insertVariable->execute([
            '018f47f2-8b6a-7c3d-9e1f-123456789ac0',
            self::DATASET_ID,
            4,
            'DestinationText',
            'destination_text',
            'string',
            1,
        ]);

        $plans = [
            new TransformationPlan(self::DATASET_ID, [
                new RecodeOperation('SourceText', 'DestinationText', [
                    new RecodeRule(
                        new ExactValueSelector(ScalarValue::string('a')),
                        new AssignValueAction(ScalarValue::string('long')),
                    ),
                    new RecodeRule(new ElseSelector(), new AssignValueAction(ScalarValue::string('x'))),
                ]),
            ]),
            new TransformationPlan(self::DATASET_ID, [
                new RecodeOperation('SourceText', 'DestinationText', [
                    new RecodeRule(
                        new ExactValueSelector(ScalarValue::string('a')),
                        new AssignValueAction(ScalarValue::string('x')),
                    ),
                    new RecodeRule(new ElseSelector(), new CopySourceAction()),
                ]),
            ]),
            new TransformationPlan(self::DATASET_ID, [
                new SetValueLabelsOperation('DestinationText', [
                    new ValueLabel(ScalarValue::string('long'), 'Too wide'),
                ]),
            ]),
        ];

        foreach ($plans as $plan) {
            try {
                (new InPlaceTransformationExecutor(new Connection($this->pdo)))->execute($plan);
                self::fail('A string operation wider than declared_string_width unexpectedly executed.');
            } catch (UnsupportedOperation $exception) {
                self::assertSame(DiagnosticCode::InvalidSourceDataset, $exception->diagnosticCode);
            }
        }
        self::assertSame(
            [null, null, null, null, null],
            $this->query('SELECT destination_text FROM respondents ORDER BY __case_ordinal')
                ->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        $names = $this->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name",
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map(static fn(mixed $name): string => (string) $name, $names));
    }

    /** @return list<string> */
    private function tableColumns(): array
    {
        $names = $this->query('PRAGMA table_info(respondents)')->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(
            static fn(array $column): string => (string) $column['name'],
            $names,
        ));
    }

    private function query(string $sql): PDOStatement
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return $statement;
    }
}
