<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\SpssAdapter;
use OpenStatSpec\Spss\SpssEngine;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\PostgreSqlProfile;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SPSS\Sav\Dataset;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableFormat;
use SPSS\Sav\VariableMetadata;
use SPSS\Sav\VariableType;

final class PostgreSqlProfileSelectionTest extends TestCase
{
    public function testPostgreSqlDriverSelectsProfileAndImportsThroughPublicAdapter(): void
    {
        $pdo = $this->pdoWithDriver('pgsql');
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $display = $this->createMock(PDOStatement::class);
        $cases = $this->createMock(PDOStatement::class);
        $engine = $this->createMock(SpssEngine::class);

        self::assertInstanceOf(PostgreSqlProfile::class, (new Connection($pdo))->profile);

        $engine->expects(self::once())
            ->method('read')
            ->with('fixture.sav')
            ->willReturn($this->fixture());
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::exactly(17))->method('exec')->willReturn(0);
        $pdo->expects(self::exactly(4))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($dataset, $variables, $display, $cases);
        $dataset->expects(self::once())
            ->method('execute')
            ->with(['fixture', 'dataset_fixture'])
            ->willReturn(true);
        $variables->expects(self::once())
            ->method('execute')
            ->with(['fixture', 1, 'Score', 'score', 'numeric', 0, 5, 8, 0, null])
            ->willReturn(true);
        $display->expects(self::once())
            ->method('execute')
            ->with(['fixture', 1, 0, 8, 0])
            ->willReturn(true);
        $cases->expects(self::once())
            ->method('execute')
            ->with(['value_0' => 1, 'value_1' => '1.5'])
            ->willReturn(true);

        (new SpssAdapter($pdo, $engine))->import('fixture.sav', 'fixture');
    }

    public function testPostgreSqlDriverSelectsProfileAndExportsStrictWideDatasetThroughPublicAdapter(): void
    {
        $pdo = $this->pdoWithDriver('pgsql');
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $cases = $this->createMock(PDOStatement::class);
        $engine = $this->createMock(SpssEngine::class);

        $pdo->expects(self::exactly(3))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($dataset, $variables, $cases);
        $dataset->expects(self::once())->method('execute')->with(['fixture'])->willReturn(true);
        $dataset->expects(self::once())->method('fetch')->willReturn(['table_name' => 'dataset_fixture']);
        $variables->expects(self::once())->method('execute')->with(['fixture'])->willReturn(true);
        $variables->expects(self::once())->method('fetchAll')->willReturn([
            ['ordinal' => 1, 'source_name' => 'Score', 'column_name' => 'score', 'storage_kind' => 'numeric', 'source_width' => 0, 'format_family' => 5, 'format_width' => 8, 'format_decimals' => 0, 'label' => 'Result'],
            ['ordinal' => 2, 'source_name' => 'Comment', 'column_name' => 'comment', 'storage_kind' => 'string', 'source_width' => 12, 'format_family' => 1, 'format_width' => 12, 'format_decimals' => 0, 'label' => null],
        ]);
        $cases->expects(self::once())->method('execute')->with()->willReturn(true);
        $cases->expects(self::exactly(3))->method('fetch')->willReturnOnConsecutiveCalls(
            ['score' => '1.5', 'comment' => 'blue'],
            ['score' => null, 'comment' => 'green'],
            false,
        );
        $engine->expects(self::once())
            ->method('write')
            ->with('fixture.zsav', self::callback(static function (Dataset $written): bool {
                self::assertSame([[1.5, 'blue'], [null, 'green']], $written->rows());
                self::assertSame('Score', $written->variables()[0]->name);
                self::assertSame('Comment', $written->variables()[1]->name);
                self::assertSame('zsav', $written->technicalMetadata->sourceFormat);

                return true;
            }));

        $result = (new SpssAdapter($pdo, $engine))->export('fixture', 'fixture.zsav');

        self::assertSame(2, $result->caseCount);
        self::assertSame('postgresql_dictionary_metadata_deferred', $result->diagnostics[0]->code);
    }

    /** @return PDO&MockObject */
    private function pdoWithDriver(string $driver): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')
            ->with(PDO::ATTR_DRIVER_NAME)
            ->willReturn($driver);

        return $pdo;
    }

    private function fixture(): Dataset
    {
        return new Dataset(
            new VariableDictionary([
                new VariableMetadata(
                    name: 'Score',
                    type: VariableType::NUMERIC,
                    width: 0,
                    printFormat: new VariableFormat(5, 8),
                    writeFormat: new VariableFormat(5, 8),
                    dictionaryIndex: 1,
                ),
            ]),
            [[1.5]],
        );
    }
}
