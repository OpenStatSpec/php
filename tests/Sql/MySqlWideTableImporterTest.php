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

        $pdo->expects(self::exactly(18))->method('exec')->willReturn(0);
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
            ['customer survey', 1, 'Score', 'score', 'numeric', 0, 5, 8, 0, 5, 8, 0, null],
            ['customer survey', 2, 'Comment', 'comment', 'string', 12, 5, 8, 0, 5, 8, 0, null],
        ], $variableRows);
        self::assertSame([
            ['value_0' => 1, 'value_1' => '0.10000000000000001', 'value_2' => 'blue'],
            ['value_0' => 2, 'value_1' => null, 'value_2' => 'green'],
        ], $caseRows);
    }

    public function testImportsCoreSpssMetadataThroughMysqlCatalogue(): void
    {
        $pdo = $this->createMock(PDO::class);
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

        $pdo->expects(self::exactly(18))->method('exec')->willReturn(0);
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
            ['customer survey', 1, 1, 'numeric', -99.0, null],
            ['customer survey', 1, 2, 'numeric', 99.0, null],
            ['customer survey', 1, 3, 'numeric', -1.0, null],
        ], $missingRows);
        self::assertSame([
            ['customer survey', 1, 1, 'numeric', 1.0, null, 'Yes'],
            ['customer survey', 1, 2, 'numeric', 2.0, null, 'No'],
        ], $labelRows);
    }

    public function testImportsV3ExtensionMetadataAndPreservesOrderedMembers(): void
    {
        $pdo = $this->createMock(PDO::class);
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

        $pdo->expects(self::exactly(18))->method('exec')->willReturn(0);
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
            ['customer survey', 1, '$Profile', 'dichotomy', 'Profile', 'numeric', 1.0, null, 'counted_values', 'variable_label'],
        ], $multipleResponseSetRows);
        self::assertSame([
            ['customer survey', 1, 1, 2],
            ['customer survey', 1, 2, 1],
        ], $multipleResponseMemberRows);
    }

    public function testCompensatesForImplicitDdlCommitWhenCaseInsertFails(): void
    {
        $pdo = $this->createMock(PDO::class);
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $cases = $this->createMock(PDOStatement::class);
        $cleanup = $this->createMock(PDOStatement::class);
        $executedSql = [];
        $cleanupSql = [];

        $pdo->expects(self::exactly(19))->method('exec')->willReturnCallback(function (string $sql) use (&$executedSql): int {
            $executedSql[] = $sql;

            return 0;
        });
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::exactly(20))->method('prepare')->willReturnCallback(
            static function (string $sql) use ($dataset, $variables, $cases, $cleanup, &$cleanupSql): PDOStatement {
                if (str_starts_with($sql, 'DELETE FROM ')) {
                    $cleanupSql[] = $sql;

                    return $cleanup;
                }

                return match (true) {
                    str_starts_with($sql, 'INSERT INTO datasets') => $dataset,
                    str_starts_with($sql, 'INSERT INTO variables') => $variables,
                    default => $cases,
                };
            },
        );

        $dataset->expects(self::once())->method('execute')->willReturn(true);
        $variables->expects(self::once())->method('execute')->willReturn(true);
        $cases->expects(self::once())->method('execute')->willThrowException(new RuntimeException('insert failed'));
        $cleanup->expects(self::exactly(17))->method('execute')->with(['customer survey'])->willReturn(true);

        $this->expectExceptionMessage('insert failed');
        try {
            (new MySqlWideTableImporter($pdo))->import([
                'variables' => [['name' => 'Score', 'type' => 'numeric']],
                'data' => [[1.0]],
            ], 'customer survey');
        } finally {
            self::assertStringStartsWith('DROP TABLE IF EXISTS ', $executedSql[18] ?? '');
            self::assertStringContainsString('dataset_customer_survey', $executedSql[18] ?? '');
            self::assertSame([
                'DELETE FROM multiple_response_set_members WHERE dataset_name = ?',
                'DELETE FROM multiple_response_sets WHERE dataset_name = ?',
                'DELETE FROM variable_set_members WHERE dataset_name = ?',
                'DELETE FROM variable_sets WHERE dataset_name = ?',
                'DELETE FROM variable_attributes WHERE dataset_name = ?',
                'DELETE FROM file_attributes WHERE dataset_name = ?',
                'DELETE FROM variable_roles WHERE dataset_name = ?',
            ], array_slice($cleanupSql, 0, 7));
        }
    }
}
