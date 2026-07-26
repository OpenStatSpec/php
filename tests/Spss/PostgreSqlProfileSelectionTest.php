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

    public function testPostgreSqlDriverSelectsProfileAndFailsExportBeforeWritingTarget(): void
    {
        $pdo = $this->pdoWithDriver('pgsql');
        $engine = $this->createMock(SpssEngine::class);
        $engine->expects($this->never())->method('write');

        $adapter = new SpssAdapter($pdo, $engine);
        $this->expectExceptionObject(new UnsupportedOperation(
            DiagnosticCode::SqlProfileOperationUnavailable,
            'The PostgreSQL profile is selected, but export is unavailable until the PostgreSQL wide-table implementation is complete. No tables were created.',
        ));

        $adapter->export('fixture', 'fixture.zsav');
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
