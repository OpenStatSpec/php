<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\PostgreSqlWideTableImporter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostgreSqlWideTableImporterTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function transactionEntryPoints(): iterable
    {
        yield 'import' => ['import'];
        yield 'create tables' => ['createTables'];
    }

    #[DataProvider('transactionEntryPoints')]
    public function testRejectsCallerOwnedTransactionBeforeMutation(string $entryPoint): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_ERRMODE)->willReturn(PDO::ERRMODE_EXCEPTION);
        $pdo->method('setAttribute')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::never())->method('exec');
        $pdo->expects(self::never())->method('prepare');

        try {
            (new PostgreSqlWideTableImporter($pdo))->$entryPoint([
                'variables' => [['name' => 'Score', 'type' => 'numeric']],
                'data' => [[1.0]],
            ], 'attempt');
            self::fail('Caller-owned transaction was accepted.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::UnsupportedOperation, $exception->diagnosticCode);
        }
    }

    public function testCreatesCatalogAndStrictWideTableInOneTransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_ERRMODE)->willReturn(PDO::ERRMODE_EXCEPTION);
        $pdo->method('setAttribute')->with(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)->willReturn(true);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::atLeast(18))->method('exec')->willReturn(0);

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
    /** @return iterable<string, array{int, int, int, list<int>}> */
    public static function caseBatches(): iterable
    {
        yield 'empty' => [0, 2, 0, []];
        yield 'singleton' => [1, 2, 0, [1]];
        yield 'two fitting rows' => [2, 2, 0, [2]];
        yield 'row limit and tail' => [513, 2, 0, [256, 256, 1]];
        yield 'parameter limit includes ordinal' => [81, 1599, 0, [40, 40, 1]];
        yield 'byte target and tail' => [5, 2, 400000, [2, 2, 1]];
        yield 'oversized valid row and following tail' => [3, 2, 1048577, [1, 2]];
    }

    /** @param positive-int $width
     * @param list<int> $batchSizes
     */
    #[DataProvider('caseBatches')]
    public function testImportsCatalogueAndOrderedRowsThroughPdoTransaction(int $count, int $width, int $textBytes, array $batchSizes): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_ERRMODE)->willReturn(PDO::ERRMODE_EXCEPTION);
        $pdo->method('setAttribute')->with(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)->willReturn(true);
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $variableRows = [];
        $caseRows = [];
        $caseSql = [];
        $sourceVariables = $width === 2 ? [
            ['name' => 'Score', 'type' => 'numeric', 'width' => 0],
            ['name' => 'Comment', 'type' => 'string', 'width' => 12],
        ] : array_map(static fn(int $i): array => ['name' => 'v' . $i, 'type' => 'numeric', 'width' => 0], range(1, $width));
        $rows = [];
        $expected = [];
        for ($i = 0; $i < $count; ++$i) {
            $row = $width === 2 ? [[0.1, null, 42, 1.0000000000000002, PHP_FLOAT_MAX, 5.0e-324][$i % 6], $i % 2 === 0 ? "õ'\\\\" : ''] : array_fill(0, $width, null);
            if ($textBytes > 0 && ($textBytes <= 1048576 || $i === 0)) {
                $row[1] = str_repeat('x', $textBytes);
            }
            $rows[] = $row;
            $expected[] = array_merge([$i + 1], array_map(static fn($value) => is_float($value) ? sprintf('%.17g', $value) : $value, $row));
        }

        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::atLeast(18))->method('exec')->willReturn(0);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($dataset, $variables, &$caseSql, &$caseRows): PDOStatement {
            if (str_starts_with($sql, 'INSERT INTO datasets ')) {
                return $dataset;
            }
            if (str_starts_with($sql, 'INSERT INTO variables ')) {
                return $variables;
            }
            self::assertStringStartsWith('INSERT INTO "dataset_customer_survey" ', $sql);
            $caseSql[] = $sql;
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function (array $params) use ($sql, &$caseRows): bool {
                $caseRows[] = [$sql, array_values($params)];
                return true;
            });
            return $statement;
        });
        $dataset->expects(self::once())->method('execute')->with(['customer survey', 'dataset_customer_survey'])->willReturn(true);
        $variables->expects(self::exactly($width))->method('execute')->willReturnCallback(function ($row) use (&$variableRows): bool {
            $variableRows[] = $row;
            return true;
        });
        $definition = (new PostgreSqlWideTableImporter($pdo))->import([
            'variables' => $sourceVariables,
            'data' => $rows,
        ], 'customer survey');

        foreach ($sourceVariables as $i => $variable) {
            $column = strtolower($variable['name']);
            self::assertSame($column, $definition->columns[$i]['columnName']);
            self::assertSame(['customer survey', $i + 1, $variable['name'], $column, $variable['type'], $variable['width'], 5, 8, 0, 5, 8, 0, null], $variableRows[$i]);
        }
        self::assertSame($batchSizes, array_map(static fn(array $batch): int => intdiv(count($batch[1]), $width + 1), $caseRows), 'Case execution sizes, including the final tail.');
        self::assertSame($count === 0, $caseSql === [], 'Empty imports must not prepare case SQL.');
        $actual = [];
        foreach ($caseRows as [$sql, $params]) {
            self::assertNotEmpty($params);
            self::assertLessThanOrEqual(65535, count($params));
            self::assertSame(count($params), preg_match_all('/\?|:value_\d+/', $sql));
            self::assertSame(intdiv(count($params), $width + 1), preg_match_all('/\\([^()]*\\)/', explode(' VALUES ', $sql, 2)[1]));
            array_push($actual, ...array_chunk($params, $width + 1));
        }
        self::assertSame($expected, $actual);
    }

    /** @return iterable<string, array{string}> */
    public static function lateBatchFailures(): iterable
    {
        yield 'prepare tail' => ['prepare'];
        yield 'execute tail' => ['execute'];
    }

    #[DataProvider('lateBatchFailures')]
    public function testSecondBatchFailureRollsBackWithoutFinalization(string $stage): void
    {
        $pdo = $this->createMock(PDO::class);
        $mode = PDO::ERRMODE_SILENT;
        $pdo->method('getAttribute')->with(PDO::ATTR_ERRMODE)->willReturn($mode);
        $pdo->method('setAttribute')->willReturnCallback(static function (int $attribute, mixed $value) use (&$mode): bool {
            self::assertSame(PDO::ATTR_ERRMODE, $attribute);
            $mode = $value;
            return true;
        });
        $pdo->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $commits = $rollbacks = $prepares = $executes = $written = 0;
        $pdo->method('commit')->willReturnCallback(static function () use (&$commits): bool {
            ++$commits;
            return true;
        });
        $pdo->method('rollBack')->willReturnCallback(static function () use (&$rollbacks): bool {
            ++$rollbacks;
            return true;
        });
        $pdo->method('exec')->willReturn(0);
        $metadata = $this->createMock(PDOStatement::class);
        $metadata->method('execute')->willReturn(true);
        $injected = new \RuntimeException('second batch ' . $stage);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($metadata, $stage, $injected, &$prepares, &$executes, &$written): PDOStatement {
            if (!str_starts_with($sql, 'INSERT INTO "dataset_attempt" ')) {
                return $metadata;
            }
            if (++$prepares === 2 && $stage === 'prepare') {
                throw $injected;
            }
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function (array $params) use ($stage, $injected, &$executes, &$written): bool {
                if (++$executes === 2 && $stage === 'execute') {
                    throw $injected;
                }
                $written += intdiv(count($params), 2);
                return true;
            });
            return $statement;
        });
        $finalized = false;
        $caught = null;
        try {
            (new PostgreSqlWideTableImporter($pdo))->import([
                'variables' => [['name' => 'Score', 'type' => 'numeric']],
                'data' => array_fill(0, 257, [0.1]),
            ], 'attempt', beforeCommit: static function () use (&$finalized): void {
                $finalized = true;
            });
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }
        self::assertSame($injected, $caught, 'The second case batch must reach the injected failure.');
        self::assertSame(256, $written, 'One full batch must succeed before the tail fails.');
        self::assertSame(0, $commits);
        self::assertSame(1, $rollbacks);
        self::assertFalse($finalized);
        self::assertSame(PDO::ERRMODE_SILENT, $mode);
    }

    public function testImportsFileLabelDocumentsAndTechnicalMetadata(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_ERRMODE)->willReturn(PDO::ERRMODE_EXCEPTION);
        $pdo->method('setAttribute')->with(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)->willReturn(true);
        $fileLabel = $this->createMock(PDOStatement::class);
        $documents = $this->createMock(PDOStatement::class);
        $technical = $this->createMock(PDOStatement::class);
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $cases = $this->createMock(PDOStatement::class);
        $documentRows = [];

        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::atLeast(18))->method('exec')->willReturn(0);
        $pdo->expects(self::exactly(6))->method('prepare')->willReturnOnConsecutiveCalls(
            $fileLabel,
            $documents,
            $technical,
            $dataset,
            $variables,
            $cases,
        );
        $fileLabel->expects(self::once())->method('execute')->with(['customer survey', 'file_label', 'Customer source'])->willReturn(true);
        $documents->expects(self::exactly(2))->method('execute')->willReturnCallback(function (array $row) use (&$documentRows): bool {
            $documentRows[] = $row;

            return true;
        });
        $technical->expects(self::once())->method('execute')->with([
            'customer survey', 'sav', '$FL2', '31.0', 'unit-test', 'UTF-8', 'SPSS', null, null,
            1, 1, 2, 1, 100.0, 1, 1, 1, 65001,
        ])->willReturn(true);
        $dataset->expects(self::once())->method('execute')->willReturn(true);
        $variables->expects(self::once())->method('execute')->willReturn(true);
        $cases->expects(self::once())->method('execute')->willReturn(true);

        (new PostgreSqlWideTableImporter($pdo))->import([
            'fileLabel' => 'Customer source',
            'documents' => ['First document line', 'Second document line'],
            'technicalMetadata' => [
                'sourceFormat' => 'sav', 'recordType' => '$FL2', 'sourceVersion' => '31.0', 'provenance' => 'unit-test',
                'encoding' => 'UTF-8', 'productName' => 'SPSS', 'caseCount' => 1, 'nominalCaseSize' => 1,
                'layoutCode' => 2, 'compression' => 1, 'compressionBias' => 100.0, 'machineCode' => 1,
                'floatingPointRepresentation' => 1, 'endianness' => 1, 'characterCode' => 65001,
            ],
            'variables' => [['name' => 'Score', 'type' => 'numeric']],
            'data' => [[1.0]],
        ], 'customer survey');

        self::assertSame([
            ['customer survey', 1, 'First document line'],
            ['customer survey', 2, 'Second document line'],
        ], $documentRows);
    }

    public function testImportsValueLabelsAndOrderedUserMissingRulesThroughPdoTransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_ERRMODE)->willReturn(PDO::ERRMODE_EXCEPTION);
        $pdo->method('setAttribute')->with(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)->willReturn(true);
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
        $pdo->expects(self::atLeast(18))->method('exec')->willReturn(0);
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
            ['customer survey', 1, 1, 'numeric', '-99.0', null],
            ['customer survey', 1, 2, 'numeric', '99.0', null],
            ['customer survey', 1, 3, 'numeric', '-1.0', null],
        ], $missingRows);
        self::assertSame([
            ['customer survey', 1, 1, 'numeric', '1.0', null, 'Yes'],
            ['customer survey', 1, 2, 'numeric', '2.0', null, 'No'],
        ], $labelRows);
    }

    public function testImportsVariableDisplayMetadataThroughPdoTransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_ERRMODE)->willReturn(PDO::ERRMODE_EXCEPTION);
        $pdo->method('setAttribute')->with(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)->willReturn(true);
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $display = $this->createMock(PDOStatement::class);
        $cases = $this->createMock(PDOStatement::class);
        $displayRows = [];

        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::atLeast(18))->method('exec')->willReturn(0);
        $pdo->expects(self::exactly(4))->method('prepare')->willReturnOnConsecutiveCalls($dataset, $variables, $display, $cases);
        $dataset->method('execute')->willReturn(true);
        $variables->method('execute')->willReturn(true);
        $display->expects(self::exactly(2))->method('execute')->willReturnCallback(function ($row) use (&$displayRows): bool {
            $displayRows[] = $row;

            return true;
        });
        $cases->expects(self::once())->method('execute')->willReturn(true);

        (new PostgreSqlWideTableImporter($pdo))->import([
            'variables' => [
                ['name' => 'Score', 'type' => 'numeric'],
                ['name' => 'Comment', 'type' => 'string'],
            ],
            'displayParameters' => [
                ['measure' => 3, 'columns' => 12, 'alignment' => 1],
                ['measure' => 1, 'columns' => 24, 'alignment' => 0],
            ],
            'data' => [[1.0, 'blue']],
        ], 'customer survey');

        self::assertSame([
            ['customer survey', 1, 3, 12, 1],
            ['customer survey', 2, 1, 24, 0],
        ], $displayRows);
    }

    public function testRejectsNullStringBeforeCommitAndRollsBack(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_ERRMODE)->willReturn(PDO::ERRMODE_EXCEPTION);
        $pdo->method('setAttribute')->with(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)->willReturn(true);
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::once())->method('inTransaction')->willReturn(false);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::never())->method('exec');
        $pdo->expects(self::never())->method('prepare');

        $this->expectExceptionMessage('SPSS string values must be non-null strings.');
        (new PostgreSqlWideTableImporter($pdo))->import([
            'variables' => [['name' => 'Comment', 'type' => 'string']],
            'data' => [[null]],
        ], 'customer survey');
    }

    public function testImportsV3AttributesAndOrderedSetMembers(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_ERRMODE)->willReturn(PDO::ERRMODE_EXCEPTION);
        $pdo->method('setAttribute')->with(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)->willReturn(true);
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $roles = $this->createMock(PDOStatement::class);
        $variableAttributes = $this->createMock(PDOStatement::class);
        $fileAttributes = $this->createMock(PDOStatement::class);
        $variableSets = $this->createMock(PDOStatement::class);
        $variableSetMembers = $this->createMock(PDOStatement::class);
        $multipleResponseSets = $this->createMock(PDOStatement::class);
        $multipleResponseSetMembers = $this->createMock(PDOStatement::class);
        $cases = $this->createMock(PDOStatement::class);
        $roleRows = [];
        $fileAttributeRows = [];
        $setMemberRows = [];
        $multipleResponseSetRows = [];

        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::atLeast(18))->method('exec')->willReturn(0);
        $pdo->expects(self::exactly(10))->method('prepare')->willReturnOnConsecutiveCalls(
            $dataset,
            $variables,
            $roles,
            $variableAttributes,
            $fileAttributes,
            $variableSets,
            $variableSetMembers,
            $multipleResponseSets,
            $multipleResponseSetMembers,
            $cases,
        );
        $dataset->method('execute')->willReturn(true);
        $variables->method('execute')->willReturn(true);
        $roles->expects(self::exactly(2))->method('execute')->willReturnCallback(function (array $row) use (&$roleRows): bool {
            $roleRows[] = $row;

            return true;
        });
        $variableAttributes->expects(self::once())->method('execute')->with(['customer survey', 1, 'Origin', 1, 'CRM'])->willReturn(true);
        $fileAttributes->expects(self::once())->method('execute')->willReturnCallback(function (array $row) use (&$fileAttributeRows): bool {
            $fileAttributeRows[] = $row;

            return true;
        });
        $variableSets->expects(self::once())->method('execute')->with(['customer survey', 1, 'Core'])->willReturn(true);
        $variableSetMembers->expects(self::exactly(2))->method('execute')->willReturnCallback(function (array $row) use (&$setMemberRows): bool {
            $setMemberRows[] = $row;

            return true;
        });
        $multipleResponseSets->expects(self::once())->method('execute')->willReturnCallback(function (array $row) use (&$multipleResponseSetRows): bool {
            $multipleResponseSetRows[] = $row;

            return true;
        });
        $multipleResponseSetMembers->expects(self::exactly(2))->method('execute')->willReturn(true);
        $cases->expects(self::once())->method('execute')->willReturn(true);

        (new PostgreSqlWideTableImporter($pdo))->import([
            'variables' => [
                ['name' => 'Respondent ID', 'type' => 'numeric', 'role' => 1, 'attributes' => [['name' => 'Origin', 'values' => ['CRM']]]],
                ['name' => 'Favourite colour', 'type' => 'string', 'role' => 0, 'attributes' => []],
            ],
            'fileAttributes' => [['name' => 'Data source', 'values' => ['survey']]],
            'variableSets' => [['name' => 'Core', 'variableNames' => ['Respondent ID', 'Favourite colour']]],
            'multipleResponseSets' => [[
                'name' => '$Profile',
                'type' => 'dichotomy',
                'variableNames' => ['Respondent ID', 'Favourite colour'],
                'label' => 'Profile',
                'countedValue' => 1.0,
                'categoryLabels' => 'counted_values',
                'labelSource' => 'variable_label',
            ]],
            'data' => [[1.0, 'blue']],
        ], 'customer survey');

        self::assertSame([
            ['customer survey', 1, 1],
            ['customer survey', 2, 0],
        ], $roleRows);
        self::assertSame([['customer survey', 'Data source', 1, 'survey']], $fileAttributeRows);
        self::assertSame([
            ['customer survey', 1, 1, 1],
            ['customer survey', 1, 2, 2],
        ], $setMemberRows);
        self::assertSame([
            ['customer survey', 1, '$Profile', 'dichotomy', 'Profile', 'numeric', '1.0', null, 'counted_values', 'variable_label'],
        ], $multipleResponseSetRows);
    }

}
