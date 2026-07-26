<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Sql;

use OpenStatSpec\Sql\PostgreSqlWideTableImporter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class PostgreSqlWideTableImporterTest extends TestCase
{
    public function testCreatesCatalogAndStrictWideTableInOneTransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::exactly(17))->method('exec')->willReturn(0);

        $definition = (new PostgreSqlWideTableImporter($pdo))->createTables([
            'variables' => [
                ['name' => 'Score', 'type' => 'numeric'],
                ['name' => 'Comment', 'type' => 'string'],
            ],
        ], 'Customer survey');

        self::assertSame('dataset_customer_survey', $definition->tableName);
        self::assertSame('score', $definition->columns[0]['columnName']);
        self::assertSame('numeric', $definition->columns[0]['storageKind']);
        self::assertSame('comment', $definition->columns[1]['columnName']);
        self::assertSame('string', $definition->columns[1]['storageKind']);
        self::assertStringContainsString('DOUBLE PRECISION NULL', $definition->createSql);
        self::assertStringContainsString('TEXT NOT NULL', $definition->createSql);
    }
    public function testImportsCatalogueAndOrderedRowsThroughPdoTransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $cases = $this->createMock(PDOStatement::class);
        $variableRows = [];
        $caseRows = [];

        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::exactly(17))->method('exec')->willReturn(0);
        $pdo->expects(self::exactly(3))->method('prepare')->willReturnOnConsecutiveCalls($dataset, $variables, $cases);
        $dataset->expects(self::once())->method('execute')->with(['customer survey', 'dataset_customer_survey'])->willReturn(true);
        $variables->expects(self::exactly(2))->method('execute')->willReturnCallback(function ($row) use (&$variableRows): bool {
            $variableRows[] = $row;
            return true;
        });
        $cases->expects(self::exactly(2))->method('execute')->willReturnCallback(function ($row) use (&$caseRows): bool {
            $caseRows[] = $row;
            return true;
        });

        $definition = (new PostgreSqlWideTableImporter($pdo))->import([
            'variables' => [
                ['name' => 'Score', 'type' => 'numeric', 'width' => 0],
                ['name' => 'Comment', 'type' => 'string', 'width' => 12],
            ],
            'data' => [[0.1, 'blue'], [null, 'green']],
        ], 'customer survey');

        self::assertSame('score', $definition->columns[0]['columnName']);
        self::assertSame('comment', $definition->columns[1]['columnName']);
        self::assertSame([
            ['customer survey', 1, 'Score', 'score', 'numeric', 0, 5, 8, 0, null],
            ['customer survey', 2, 'Comment', 'comment', 'string', 12, 5, 8, 0, null],
        ], $variableRows);
        self::assertSame([
            ['value_0' => 1, 'value_1' => '0.10000000000000001', 'value_2' => 'blue'],
            ['value_0' => 2, 'value_1' => null, 'value_2' => 'green'],
        ], $caseRows);
    }

    public function testImportsValueLabelsAndOrderedUserMissingRulesThroughPdoTransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $missing = $this->createMock(PDOStatement::class);
        $missingValues = $this->createMock(PDOStatement::class);
        $labels = $this->createMock(PDOStatement::class);
        $cases = $this->createMock(PDOStatement::class);
        $missingRows = [];
        $labelRows = [];

        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::exactly(17))->method('exec')->willReturn(0);
        $pdo->expects(self::exactly(6))->method('prepare')->willReturnOnConsecutiveCalls($dataset, $variables, $missing, $missingValues, $labels, $cases);
        $dataset->expects(self::once())->method('execute')->willReturn(true);
        $variables->expects(self::once())->method('execute')->willReturn(true);
        $missing->expects(self::once())->method('execute')->with(['customer survey', 1, 3])->willReturn(true);
        $missingValues->expects(self::exactly(3))->method('execute')->willReturnCallback(function ($row) use (&$missingRows): bool {
            $missingRows[] = $row;
            return true;
        });
        $labels->expects(self::exactly(2))->method('execute')->willReturnCallback(function ($row) use (&$labelRows): bool {
            $labelRows[] = $row;
            return true;
        });
        $cases->expects(self::once())->method('execute')->willReturn(true);

        (new PostgreSqlWideTableImporter($pdo))->import([
            'variables' => [[
                'name' => 'Score',
                'type' => 'numeric',
                'missingFormat' => 3,
                'missingValues' => [-99.0, 99.0, -1.0],
            ]],
            'data' => [[1.0]],
            'valueLabels' => [[
                'indexes' => [0],
                'labels' => [
                    ['value' => 1.0, 'label' => 'Yes'],
                    ['value' => 2.0, 'label' => 'No'],
                ],
            ]],
        ], 'customer survey');

        self::assertSame([
            ['customer survey', 1, 1, 'numeric', -99.0, null],
            ['customer survey', 1, 2, 'numeric', 99.0, null],
            ['customer survey', 1, 3, 'numeric', -1.0, null],
        ], $missingRows);
        self::assertSame([
            ['customer survey', 1, 1, 'numeric', 1.0, null, 'Yes'],
            ['customer survey', 1, 2, 'numeric', 2.0, null, 'No'],
        ], $labelRows);
    }

    public function testRejectsNullStringBeforeCommitAndRollsBack(): void
    {
        $pdo = $this->createMock(PDO::class);
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $cases = $this->createMock(PDOStatement::class);

        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::once())->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $pdo->expects(self::exactly(17))->method('exec')->willReturn(0);
        $pdo->expects(self::exactly(3))->method('prepare')->willReturnOnConsecutiveCalls($dataset, $variables, $cases);
        $dataset->method('execute')->willReturn(true);
        $variables->method('execute')->willReturn(true);
        $cases->expects(self::never())->method('execute');

        $this->expectExceptionMessage('SPSS string values must be non-null strings.');
        (new PostgreSqlWideTableImporter($pdo))->import([
            'variables' => [['name' => 'Comment', 'type' => 'string']],
            'data' => [[null]],
        ], 'customer survey');
    }

}
