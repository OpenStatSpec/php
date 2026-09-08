<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\DoltProfile;
use OpenStatSpec\Sql\MySqlWideTableImporter;
use OpenStatSpec\Sql\MySqlProfile;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MySqlWideTableImporterTest extends TestCase
{
    public function testRejectsCallerOwnedTransactionBeforeMutation(): void
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
            (new MySqlWideTableImporter($pdo))->import([
                'variables' => [['name' => 'Score', 'type' => 'numeric']],
                'data' => [[1.0]],
            ], 'attempt');
            self::fail('Caller-owned transaction was accepted.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::UnsupportedOperation, $exception->diagnosticCode);
        }
    }

    /** @return iterable<string, array{MySqlProfile, int, int, int, int, list<int>}> */
    public static function caseBatches(): iterable
    {
        foreach (['mysql' => new MySqlProfile(), 'dolt' => new DoltProfile()] as $name => $profile) {
            foreach ([0 => [], 1 => [1], 2 => [2], 513 => [256, 256, 1]] as $count => $sizes) {
                yield "$name $count rows" => [$profile, $count, 2, 0, 1073741824, $sizes];
            }
            $width = $profile->maximumSourceVariables();
            $limit = intdiv(65535, $width + 1);
            yield "$name parameter limit includes ordinal" => [$profile, 2 * $limit + 1, $width, 0, 1073741824, [$limit, $limit, 1]];
            yield "$name byte target" => [$profile, 41, 2, 50000, 1073741824, [20, 20, 1]];
            // Real packet 231072 gives a 50000-byte payload, not another halving/reserve.
            yield "$name packet full and tail" => [$profile, 5, 2, 20000, 231072, [2, 2, 1]];
            yield "$name preflight boundary singleton" => [$profile, 1, 2, 49992, 231072, [1]];
            yield "$name preflight boundary with following tail" => [$profile, 3, 2, 49992, 231072, [1, 2]];
        }
    }

    /** @param positive-int $width
     * @param list<int> $batchSizes
     */
    #[DataProvider('caseBatches')]
    public function testImportsCatalogueAndOrderedRowsAfterMysqlDdl(MySqlProfile $profile, int $count, int $width, int $textBytes, int $packet, array $batchSizes): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_ERRMODE)->willReturn(PDO::ERRMODE_EXCEPTION);
        $pdo->method('setAttribute')->with(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)->willReturn(true);
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $variableRows = [];
        $caseRows = [];
        $caseSql = [];
        $probes = [];
        $ddlStarted = false;
        $packetStatement = $this->createMock(PDOStatement::class);
        $packetStatement->method('fetchColumn')->willReturn($packet);
        $pdo->method('query')->willReturnCallback(static function (string $sql) use ($packetStatement, &$probes, &$ddlStarted): PDOStatement|false {
            if ($sql !== 'SELECT @@max_allowed_packet') {
                return false;
            }
            $probes[] = $ddlStarted;
            return $packetStatement;
        });
        $sourceVariables = $width === 2 ? [
            ['name' => 'Score', 'type' => 'numeric', 'width' => 0],
            ['name' => 'Comment', 'type' => 'string', 'width' => 12],
        ] : array_map(static fn(int $i): array => ['name' => 'v' . $i, 'type' => 'numeric', 'width' => 0], range(1, $width));
        $rows = [];
        $expected = [];
        for ($i = 0; $i < $count; ++$i) {
            $row = $width === 2 ? [[0.1, null, 42, 1.0000000000000002, PHP_FLOAT_MAX, 5.0e-324][$i % 6], $i % 2 === 0 ? "õ'\\\\" : ''] : array_fill(0, $width, null);
            if ($textBytes > 0 && ($textBytes !== 49992 || $i === 0)) {
                $row[1] = str_repeat("'\\\\", intdiv($textBytes, 3));
                $row[1] .= str_repeat('x', $textBytes - strlen($row[1]));
            }
            $rows[] = $row;
            $expected[] = array_merge([$i + 1], array_map(static fn($value) => is_float($value) ? sprintf('%.17g', $value) : $value, $row));
        }

        $pdo->expects(self::atLeast(18))->method('exec')->willReturnCallback(static function () use (&$ddlStarted): int {
            $ddlStarted = true;
            return 0;
        });
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($dataset, $variables, &$caseSql, &$caseRows): PDOStatement {
            if (str_starts_with($sql, 'INSERT INTO datasets ')) {
                return $dataset;
            }
            if (str_starts_with($sql, 'INSERT INTO variables ')) {
                return $variables;
            }
            self::assertStringStartsWith('INSERT INTO `dataset_customer_survey` ', $sql);
            $caseSql[] = $sql;
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function (array $params) use ($sql, &$caseRows): bool {
                $caseRows[] = [$sql, array_values($params)];
                return true;
            });
            return $statement;
        });

        $dataset->expects(self::once())->method('execute')->with(['customer survey', 'dataset_customer_survey'])->willReturn(true);
        $variables->expects(self::exactly($width))->method('execute')->willReturnCallback(function (array $row) use (&$variableRows): bool {
            $variableRows[] = $row;

            return true;
        });
        $definition = (new MySqlWideTableImporter($pdo, $profile))->import([
            'variables' => $sourceVariables,
            'data' => $rows,
        ], 'customer survey');

        self::assertSame('dataset_customer_survey', $definition->tableName);
        foreach ($sourceVariables as $i => $variable) {
            $column = strtolower($variable['name']);
            self::assertSame($column, $definition->columns[$i]['columnName']);
            self::assertSame(['customer survey', $i + 1, $variable['name'], $column, $variable['type'], $variable['width'], 5, 8, 0, 5, 8, 0, null], $variableRows[$i]);
        }
        self::assertSame($batchSizes, array_map(static fn(array $batch): int => intdiv(count($batch[1]), $width + 1), $caseRows), 'Case execution sizes, including the final tail.');
        self::assertSame($count === 0, $caseSql === [], 'Empty imports must not prepare case SQL.');
        self::assertSame(array_fill(0, $count < 2 ? 2 : 3, false), $probes, 'Two preflight probes plus one batch-budget snapshot for multiple rows; all before DDL.');
        $actual = [];
        foreach ($caseRows as [$sql, $params]) {
            self::assertNotEmpty($params);
            self::assertLessThanOrEqual(65535, count($params));
            self::assertSame(count($params), preg_match_all('/\\?|:value_\\d+/', $sql));
            self::assertSame(intdiv(count($params), $width + 1), preg_match_all('/\\([^()]*\\)/', explode(' VALUES ', $sql, 2)[1]));
            array_push($actual, ...array_chunk($params, $width + 1));
        }
        self::assertSame($expected, $actual);
    }

    public function testImportsCoreSpssMetadataThroughMysqlCatalogue(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_ERRMODE)->willReturn(PDO::ERRMODE_EXCEPTION);
        $pdo->method('setAttribute')->with(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)->willReturn(true);
        $fileLabel = $this->createMock(PDOStatement::class);
        $documents = $this->createMock(PDOStatement::class);
        $technical = $this->createMock(PDOStatement::class);
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $display = $this->createMock(PDOStatement::class);
        $missing = $this->createMock(PDOStatement::class);
        $missingValues = $this->createMock(PDOStatement::class);
        $labels = $this->createMock(PDOStatement::class);
        $cases = $this->createMock(PDOStatement::class);
        $documentRows = [];
        $displayRows = [];
        $missingRows = [];
        $labelRows = [];

        $pdo->expects(self::atLeast(18))->method('exec')->willReturn(0);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::exactly(10))->method('prepare')->willReturnOnConsecutiveCalls(
            $fileLabel,
            $documents,
            $technical,
            $dataset,
            $variables,
            $display,
            $missing,
            $missingValues,
            $labels,
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
        $display->expects(self::once())->method('execute')->willReturnCallback(function (array $row) use (&$displayRows): bool {
            $displayRows[] = $row;

            return true;
        });
        $missing->expects(self::once())->method('execute')->with(['customer survey', 1, 3])->willReturn(true);
        $missingValues->expects(self::exactly(3))->method('execute')->willReturnCallback(function (array $row) use (&$missingRows): bool {
            $missingRows[] = $row;

            return true;
        });
        $labels->expects(self::exactly(2))->method('execute')->willReturnCallback(function (array $row) use (&$labelRows): bool {
            $labelRows[] = $row;

            return true;
        });
        $cases->expects(self::once())->method('execute')->willReturn(true);

        (new MySqlWideTableImporter($pdo))->import([
            'fileLabel' => 'Customer source',
            'documents' => ['First document line', 'Second document line'],
            'technicalMetadata' => [
                'sourceFormat' => 'sav', 'recordType' => '$FL2', 'sourceVersion' => '31.0', 'provenance' => 'unit-test',
                'encoding' => 'UTF-8', 'productName' => 'SPSS', 'caseCount' => 1, 'nominalCaseSize' => 1,
                'layoutCode' => 2, 'compression' => 1, 'compressionBias' => 100.0, 'machineCode' => 1,
                'floatingPointRepresentation' => 1, 'endianness' => 1, 'characterCode' => 65001,
            ],
            'variables' => [[
                'name' => 'Score', 'type' => 'numeric', 'missingFormat' => 3,
                'missingValues' => [-99.0, 99.0, -1.0],
            ]],
            'displayParameters' => [['measure' => 3, 'columns' => 12, 'alignment' => 1]],
            'valueLabels' => [[
                'indexes' => [0],
                'labels' => [['value' => 1.0, 'label' => 'Yes'], ['value' => 2.0, 'label' => 'No']],
            ]],
            'data' => [[1.0]],
        ], 'customer survey');

        self::assertSame([
            ['customer survey', 1, 'First document line'],
            ['customer survey', 2, 'Second document line'],
        ], $documentRows);
        self::assertSame([['customer survey', 1, 3, 12, 1]], $displayRows);
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

    public function testImportsV3ExtensionMetadataAndPreservesOrderedMembers(): void
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
        $variableSetMemberRows = [];
        $multipleResponseSetRows = [];
        $multipleResponseMemberRows = [];

        $pdo->expects(self::atLeast(18))->method('exec')->willReturn(0);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
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

        $dataset->expects(self::once())->method('execute')->willReturn(true);
        $variables->expects(self::exactly(2))->method('execute')->willReturn(true);
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
        $variableSetMembers->expects(self::exactly(2))->method('execute')->willReturnCallback(function (array $row) use (&$variableSetMemberRows): bool {
            $variableSetMemberRows[] = $row;

            return true;
        });
        $multipleResponseSets->expects(self::once())->method('execute')->willReturnCallback(function (array $row) use (&$multipleResponseSetRows): bool {
            $multipleResponseSetRows[] = $row;

            return true;
        });
        $multipleResponseSetMembers->expects(self::exactly(2))->method('execute')->willReturnCallback(function (array $row) use (&$multipleResponseMemberRows): bool {
            $multipleResponseMemberRows[] = $row;

            return true;
        });
        $cases->expects(self::once())->method('execute')->willReturn(true);

        (new MySqlWideTableImporter($pdo))->import([
            'variables' => [
                ['name' => 'Respondent ID', 'type' => 'numeric', 'role' => 1, 'attributes' => [['name' => 'Origin', 'values' => ['CRM']]]],
                ['name' => 'Favourite colour', 'type' => 'string', 'role' => 0, 'attributes' => []],
            ],
            'fileAttributes' => [['name' => 'Data source', 'values' => ['survey']]],
            'variableSets' => [['name' => 'Core', 'variableNames' => ['Favourite colour', 'Respondent ID']]],
            'multipleResponseSets' => [[
                'name' => '$Profile',
                'type' => 'dichotomy',
                'variableNames' => ['Favourite colour', 'Respondent ID'],
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
            ['customer survey', 1, 1, 2],
            ['customer survey', 1, 2, 1],
        ], $variableSetMemberRows);
        self::assertSame([
            ['customer survey', 1, '$Profile', 'dichotomy', 'Profile', 'numeric', '1.0', null, 'counted_values', 'variable_label'],
        ], $multipleResponseSetRows);
        self::assertSame([
            ['customer survey', 1, 1, 2],
            ['customer survey', 1, 2, 1],
        ], $multipleResponseMemberRows);
    }

    public function testInjectedDoltProfileRejects306VariablesBeforeDdl(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_ERRMODE)->willReturn(PDO::ERRMODE_EXCEPTION);
        $pdo->method('setAttribute')->with(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)->willReturn(true);
        $pdo->expects(self::never())->method('exec');
        $pdo->expects(self::never())->method('prepare');
        $variables = [];
        for ($index = 1; $index <= 306; ++$index) {
            $variables[] = ['name' => 'v' . $index, 'type' => 'numeric'];
        }

        try {
            (new MySqlWideTableImporter($pdo, new DoltProfile()))->import([
                'variables' => $variables,
                'data' => [],
            ], 'too wide');
            self::fail('Expected Dolt preflight to reject 306 source variables.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function lateBatchFailures(): iterable
    {
        yield 'prepare tail' => ['prepare'];
        yield 'execute tail' => ['execute'];
    }

    #[DataProvider('lateBatchFailures')]
    public function testRolledBackCaseInsertFailureDropsOnlyAttemptPhysicalTable(string $stage): void
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
        $executedSql = [];
        $pdo->method('exec')->willReturnCallback(static function (string $sql) use (&$executedSql): int {
            $executedSql[] = $sql;
            return 0;
        });
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $dataset->expects(self::once())->method('execute')->with(['customer survey', 'dataset_customer_survey'])->willReturn(true);
        $variables->expects(self::once())->method('execute')->willReturn(true);
        $injected = new RuntimeException('second batch ' . $stage);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($dataset, $variables, $stage, $injected, &$prepares, &$executes, &$written): PDOStatement {
            if (str_starts_with($sql, 'INSERT INTO datasets ')) {
                return $dataset;
            }
            if (str_starts_with($sql, 'INSERT INTO variables ')) {
                return $variables;
            }
            self::assertStringStartsWith('INSERT INTO `dataset_customer_survey` ', $sql);
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
        $caught = null;
        try {
            (new MySqlWideTableImporter($pdo))->import([
                'variables' => [['name' => 'Score', 'type' => 'numeric']],
                'data' => array_fill(0, 257, [0.1]),
            ], 'customer survey');
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }
        self::assertSame($injected, $caught, 'The second case batch must reach the injected failure.');
        self::assertSame(256, $written, 'One full batch must succeed before the tail fails.');
        self::assertSame(0, $commits);
        self::assertSame(1, $rollbacks);
        self::assertSame(PDO::ERRMODE_SILENT, $mode);
        self::assertCount(22, $executedSql);
        self::assertSame(['DROP TABLE IF EXISTS `dataset_customer_survey`'], array_values(array_filter($executedSql, static fn(string $sql): bool => str_starts_with($sql, 'DROP '))));
        self::assertSame('DROP TABLE IF EXISTS `dataset_customer_survey`', $executedSql[21]);
    }

    #[DataProvider('nonFiniteValues')]
    public function testDoltRejectsEveryNonFiniteValueBeforeDdl(float $value): void
    {
        $pdo = $this->doltPreflightPdo();
        $pdo->expects(self::never())->method('exec');
        $pdo->expects(self::never())->method('prepare');

        try {
            (new MySqlWideTableImporter($pdo, new DoltProfile()))->import([
                'variables' => [['name' => 'Score', 'type' => 'numeric']],
                'data' => [[$value]],
            ], 'non finite');
            self::fail('Dolt accepted a non-finite value.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
            self::assertStringContainsString('non-finite', $exception->getMessage());
        }
    }

    public function testDoltRejectsMissingWeightAndNonFiniteDictionaryBeforeDdl(): void
    {
        foreach ([
            [
                'variables' => [['name' => 'Score', 'type' => 'numeric']],
                'weightVariableName' => 'Missing',
                'data' => [[1.0]],
            ],
            [
                'variables' => [[
                    'name' => 'Score',
                    'type' => 'numeric',
                    'missingFormat' => 1,
                    'missingValues' => [NAN],
                ]],
                'data' => [[1.0]],
            ],
        ] as $source) {
            $pdo = $this->doltPreflightPdo();
            $pdo->expects(self::never())->method('exec');
            $pdo->expects(self::never())->method('prepare');

            try {
                (new MySqlWideTableImporter($pdo, new DoltProfile()))->import($source, 'invalid metadata');
                self::fail('Dolt accepted invalid source metadata.');
            } catch (UnsupportedOperation) {
            }
        }
    }

    /** @param array<string, mixed> $metadata */
    #[DataProvider('invalidV3Metadata')]
    public function testDoltRejectsInvalidV3MetadataBeforeDdl(array $metadata, DiagnosticCode $diagnosticCode): void
    {
        $pdo = $this->doltPreflightPdo();
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('exec');
        $pdo->expects(self::never())->method('prepare');
        $source = array_merge([
            'variables' => [[
                'name' => 'Score',
                'type' => 'numeric',
                'role' => 0,
                'attributes' => [],
            ]],
            'data' => [[1.0]],
        ], $metadata);

        try {
            (new MySqlWideTableImporter($pdo, new DoltProfile()))->import($source, 'invalid v3 metadata');
            self::fail('Dolt accepted invalid V3 metadata.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame($diagnosticCode, $exception->diagnosticCode);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, DiagnosticCode}> */
    public static function invalidV3Metadata(): iterable
    {
        yield 'malformed file attributes' => [
            ['fileAttributes' => [['name' => 'Broken', 'values' => 'not-a-list']]],
            DiagnosticCode::InvalidSourceDataset,
        ];
        yield 'unknown variable-set member' => [
            ['variableSets' => [['name' => 'Core', 'variableNames' => ['Missing']]]],
            DiagnosticCode::InvalidSourceDataset,
        ];
        yield 'duplicate variable-set member' => [
            ['variableSets' => [['name' => 'Core', 'variableNames' => ['Score', 'Score']]]],
            DiagnosticCode::InvalidSourceDataset,
        ];
        yield 'duplicate multiple-response-set member' => [[
            'multipleResponseSets' => [[
                'name' => '$Set',
                'type' => 'dichotomy',
                'variableNames' => ['Score', 'Score'],
                'label' => null,
                'countedValue' => 1.0,
                'categoryLabels' => 'counted_values',
                'labelSource' => 'variable_label',
            ]],
        ], DiagnosticCode::InvalidSourceDataset];
        yield 'non-finite multiple-response counted value' => [[
            'multipleResponseSets' => [[
                'name' => '$Set',
                'type' => 'dichotomy',
                'variableNames' => ['Score'],
                'label' => null,
                'countedValue' => INF,
                'categoryLabels' => 'counted_values',
                'labelSource' => 'variable_label',
            ]],
        ], DiagnosticCode::TargetCapabilityExceeded];
    }

    /** @return iterable<string, array{float}> */
    public static function nonFiniteValues(): iterable
    {
        yield 'NaN' => [NAN];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
    }

    /** @return PDO&MockObject */
    private function doltPreflightPdo(): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_ERRMODE)->willReturn(PDO::ERRMODE_EXCEPTION);
        $pdo->method('setAttribute')->with(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)->willReturn(true);
        $statement = $this->createMock(PDOStatement::class);
        $pdo->method('query')->with('SELECT @@max_allowed_packet')->willReturn($statement);
        $statement->method('fetchColumn')->willReturn('1073741824');

        return $pdo;
    }
}
