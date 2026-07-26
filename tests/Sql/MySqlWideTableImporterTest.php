<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Sql;

use OpenStatSpec\Sql\MySqlWideTableImporter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MySqlWideTableImporterTest extends TestCase
{
    public function testImportsCatalogueAndOrderedRowsAfterMysqlDdl(): void
    {
        $pdo = $this->createMock(PDO::class);
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $cases = $this->createMock(PDOStatement::class);
        $variableRows = [];
        $caseRows = [];

        $pdo->expects(self::exactly(17))->method('exec')->willReturn(0);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::exactly(3))->method('prepare')->willReturnOnConsecutiveCalls($dataset, $variables, $cases);

        $dataset->expects(self::once())->method('execute')->with(['customer survey', 'dataset_customer_survey'])->willReturn(true);
        $variables->expects(self::exactly(2))->method('execute')->willReturnCallback(function (array $row) use (&$variableRows): bool {
            $variableRows[] = $row;

            return true;
        });
        $cases->expects(self::exactly(2))->method('execute')->willReturnCallback(function (array $row) use (&$caseRows): bool {
            $caseRows[] = $row;

            return true;
        });

        $definition = (new MySqlWideTableImporter($pdo))->import([
            'variables' => [
                ['name' => 'Score', 'type' => 'numeric', 'width' => 0],
                ['name' => 'Comment', 'type' => 'string', 'width' => 12],
            ],
            'data' => [[0.1, 'blue'], [null, 'green']],
        ], 'customer survey');

        self::assertSame('dataset_customer_survey', $definition->tableName);
        self::assertSame([
            ['customer survey', 1, 'Score', 'score', 'numeric', 0, 5, 8, 0, null],
            ['customer survey', 2, 'Comment', 'comment', 'string', 12, 5, 8, 0, null],
        ], $variableRows);
        self::assertSame([
            ['value_0' => 1, 'value_1' => '0.10000000000000001', 'value_2' => 'blue'],
            ['value_0' => 2, 'value_1' => null, 'value_2' => 'green'],
        ], $caseRows);
    }

    public function testCompensatesForImplicitDdlCommitWhenCaseInsertFails(): void
    {
        $pdo = $this->createMock(PDO::class);
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $cases = $this->createMock(PDOStatement::class);
        $deleteVariables = $this->createMock(PDOStatement::class);
        $deleteDataset = $this->createMock(PDOStatement::class);
        $executedSql = [];

        $pdo->expects(self::exactly(18))->method('exec')->willReturnCallback(function (string $sql) use (&$executedSql): int {
            $executedSql[] = $sql;

            return 0;
        });
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::exactly(5))->method('prepare')->willReturnOnConsecutiveCalls(
            $dataset,
            $variables,
            $cases,
            $deleteVariables,
            $deleteDataset,
        );

        $dataset->expects(self::once())->method('execute')->willReturn(true);
        $variables->expects(self::once())->method('execute')->willReturn(true);
        $cases->expects(self::once())->method('execute')->willThrowException(new RuntimeException('insert failed'));
        $deleteVariables->expects(self::once())->method('execute')->with(['customer survey'])->willReturn(true);
        $deleteDataset->expects(self::once())->method('execute')->with(['customer survey'])->willReturn(true);

        $this->expectExceptionMessage('insert failed');
        try {
            (new MySqlWideTableImporter($pdo))->import([
                'variables' => [['name' => 'Score', 'type' => 'numeric']],
                'data' => [[1.0]],
            ], 'customer survey');
        } finally {
            self::assertStringStartsWith('DROP TABLE IF EXISTS ', $executedSql[17] ?? '');
            self::assertStringContainsString('dataset_customer_survey', $executedSql[17] ?? '');
        }
    }
}
