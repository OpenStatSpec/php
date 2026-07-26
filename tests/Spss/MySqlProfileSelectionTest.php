<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\SpssAdapter;
use OpenStatSpec\Spss\SpssEngine;
use OpenStatSpec\Tests\Support\FakeSpssEngine;
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

    public function testMysqlExportUsesWideTableExporterThroughPublicAdapter(): void
    {
        $pdo = $this->pdoWithDriver('mysql');
        $datasets = $this->statement([['table_name' => 'dataset_fixture']]);
        $variables = $this->statement([], [
            ['ordinal' => '1', 'source_name' => 'Score', 'column_name' => 'score', 'storage_kind' => 'numeric', 'source_width' => '0', 'format_family' => '5', 'format_width' => '8', 'format_decimals' => '0', 'label' => 'Score label'],
            ['ordinal' => '2', 'source_name' => 'Name', 'column_name' => 'name', 'storage_kind' => 'string', 'source_width' => '24', 'format_family' => '1', 'format_width' => '24', 'format_decimals' => '0', 'label' => null],
        ]);
        $cases = $this->statement([
            ['score' => '1.5', 'name' => 'Ada'],
            ['score' => null, 'name' => 'Bea'],
        ]);
        $pdo->method('prepare')->willReturnCallback(static function (string $sql) use ($datasets, $variables, $cases): PDOStatement {
            return match (true) {
                str_contains($sql, 'FROM datasets') => $datasets,
                str_contains($sql, 'SELECT ordinal, source_name') => $variables,
                str_starts_with($sql, 'SELECT ') => $cases,
                default => throw new \LogicException('Unexpected MySQL export query: ' . $sql),
            };
        });

        $engine = new FakeSpssEngine($this->fixture());
        $result = (new SpssAdapter($pdo, $engine))->export('fixture', 'fixture.sav');
        $written = $engine->lastWrite()['dataset'];

        self::assertSame([[1.5, 'Ada'], [null, 'Bea']], $written->rows());
        self::assertSame(['Score', 'Name'], array_map(static fn(VariableMetadata $variable): string => $variable->name, $written->variables()));
        self::assertSame(2, $result->caseCount);
        self::assertSame(
            ['deferred_dictionary_metadata', 'deferred_variable_extensions', 'deferred_file_metadata'],
            array_map(static fn($diagnostic): string => $diagnostic->code, $result->diagnostics),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $all
     */
    private function statement(array $rows = [], array $all = []): PDOStatement
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn($all);
        $index = 0;
        $statement->method('fetch')->willReturnCallback(static function () use ($rows, &$index): array|false {
            return $rows[$index++] ?? false;
        });

        return $statement;
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
