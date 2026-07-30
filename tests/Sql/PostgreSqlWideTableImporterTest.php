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
        $pdo->expects(self::atLeast(18))->method('exec')->willReturn(0);
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
            ['customer survey', 1, 'Score', 'score', 'numeric', 0, 5, 8, 0, 5, 8, 0, null],
            ['customer survey', 2, 'Comment', 'comment', 'string', 12, 5, 8, 0, 5, 8, 0, null],
        ], $variableRows);
        self::assertSame([
            ['value_0' => 1, 'value_1' => '0.10000000000000001', 'value_2' => 'blue'],
            ['value_0' => 2, 'value_1' => null, 'value_2' => 'green'],
        ], $caseRows);
    }

    public function testImportsFileLabelDocumentsAndTechnicalMetadata(): void
    {
        $pdo = $this->createMock(PDO::class);
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
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::never())->method('inTransaction');
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
            ['customer survey', 1, '$Profile', 'dichotomy', 'Profile', 'numeric', 1.0, null, 'counted_values', 'variable_label'],
        ], $multipleResponseSetRows);
    }

}
