<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\SpssAdapter;
use OpenStatSpec\Spss\SpssEngine;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\MySqlProfile;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SPSS\Sav\Dataset;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableFormat;
use SPSS\Sav\VariableMetadata;
use SPSS\Sav\VariableType;

final class MySqlProfileSelectionTest extends TestCase
{
    public function testMysqlDriverSelectsProfileAndImportsThroughPublicAdapter(): void
    {
        $pdo = $this->pdoWithDriver('mysql');
        $technical = $this->createMock(PDOStatement::class);
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $display = $this->createMock(PDOStatement::class);
        $roles = $this->createMock(PDOStatement::class);
        $variableAttributes = $this->createMock(PDOStatement::class);
        $fileAttributes = $this->createMock(PDOStatement::class);
        $variableSets = $this->createMock(PDOStatement::class);
        $variableSetMembers = $this->createMock(PDOStatement::class);
        $multipleResponseSets = $this->createMock(PDOStatement::class);
        $multipleResponseSetMembers = $this->createMock(PDOStatement::class);
        $cases = $this->createMock(PDOStatement::class);
        $engine = $this->createMock(SpssEngine::class);

        self::assertInstanceOf(MySqlProfile::class, (new Connection($pdo))->profile);

        $engine->expects(self::once())->method('read')->with('fixture.sav')->willReturn($this->fixture());
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::exactly(17))->method('exec')->willReturn(0);
        $pdo->expects(self::exactly(12))->method('prepare')->willReturnOnConsecutiveCalls(
            $technical,
            $dataset,
            $variables,
            $display,
            $roles,
            $variableAttributes,
            $fileAttributes,
            $variableSets,
            $variableSetMembers,
            $multipleResponseSets,
            $multipleResponseSetMembers,
            $cases,
        );
        $dataset->expects(self::once())->method('execute')->with(['fixture', 'dataset_fixture'])->willReturn(true);
        $variables->expects(self::once())->method('execute')->with(['fixture', 1, 'Score', 'score', 'numeric', 0, 5, 8, 0, null])->willReturn(true);
        $display->expects(self::once())->method('execute')->with(['fixture', 1, 0, 8, 0])->willReturn(true);
        $roles->expects(self::once())->method('execute')->with(['fixture', 1, 0])->willReturn(true);
        $cases->expects(self::once())->method('execute')->with(['value_0' => 1, 'value_1' => '1.5'])->willReturn(true);

        (new SpssAdapter($pdo, $engine))->import('fixture.sav', 'fixture');
    }

    public function testMysqlExportFailsInsteadOfUsingSqliteExporter(): void
    {
        $pdo = $this->pdoWithDriver('mysql');
        $adapter = new SpssAdapter($pdo, $this->createMock(SpssEngine::class));

        try {
            $adapter->export('fixture', 'fixture.sav');
            self::fail('Expected an unavailable MySQL/MariaDB export operation.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::SqlProfileOperationUnavailable, $exception->diagnosticCode);
        }
    }

    /** @return PDO&MockObject */
    private function pdoWithDriver(string $driver): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn($driver);

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
