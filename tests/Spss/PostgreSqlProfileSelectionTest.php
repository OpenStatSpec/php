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
use SPSS\Sav\Alignment;
use SPSS\Sav\Dataset;
use SPSS\Sav\Measure;
use SPSS\Sav\MissingValues;
use SPSS\Sav\ValueLabel;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableFormat;
use SPSS\Sav\VariableMetadata;
use SPSS\Sav\VariableType;

final class PostgreSqlProfileSelectionTest extends TestCase
{
    public function testPostgreSqlDriverSelectsProfileAndImportsThroughPublicAdapter(): void
    {
        $pdo = $this->pdoWithDriver('pgsql');
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
        $journal = $this->createMock(PDOStatement::class);
        $engine = $this->createMock(SpssEngine::class);

        self::assertInstanceOf(PostgreSqlProfile::class, (new Connection($pdo))->profile);

        $engine->expects(self::once())
            ->method('read')
            ->with('fixture.sav')
            ->willReturn($this->fixture());
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::exactly(19))->method('exec')->willReturn(0);
        $pdo->expects(self::exactly(14))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls(
                $journal,
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
                $journal,
            );
        $journal->expects(self::exactly(2))->method('execute')->willReturnCallback(static function (array $values): bool {
            if (($values[1] ?? null) === 'import') {
                return count($values) === 8 && $values[2] === 'running' && $values[3] === null && $values[4] === 'fixture.sav';
            }

            return $values[0] === 'succeeded' && $values[1] === 'fixture' && is_string($values[2] ?? null);
        });
        $technical->expects(self::once())
            ->method('execute')
            ->with(['fixture', 'sav', null, null, null, 'UTF-8', null, null, null, null, null, null, null, null, null, null, null, null])
            ->willReturn(true);
        $dataset->expects(self::once())
            ->method('execute')
            ->with(['fixture', 'dataset_fixture'])
            ->willReturn(true);
        $variables->expects(self::once())
            ->method('execute')
            ->with(['fixture', 1, 'Score', 'score', 'numeric', 0, 5, 8, 0, 5, 8, 0, null])
            ->willReturn(true);
        $display->expects(self::once())
            ->method('execute')
            ->with(['fixture', 1, 0, 8, 0])
            ->willReturn(true);
        $roles->expects(self::once())
            ->method('execute')
            ->with(['fixture', 1, 0])
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
        $fileLabel = $this->createMock(PDOStatement::class);
        $documents = $this->createMock(PDOStatement::class);
        $technical = $this->createMock(PDOStatement::class);
        $scoreLabels = $this->createMock(PDOStatement::class);
        $scoreMissing = $this->createMock(PDOStatement::class);
        $commentLabels = $this->createMock(PDOStatement::class);
        $commentMissing = $this->createMock(PDOStatement::class);
        $scoreDisplay = $this->createMock(PDOStatement::class);
        $commentDisplay = $this->createMock(PDOStatement::class);
        $roleOne = $this->emptyCatalogueStatement(0);
        $attributesOne = $this->emptyCatalogueStatement();
        $roleTwo = $this->emptyCatalogueStatement(0);
        $attributesTwo = $this->emptyCatalogueStatement();
        $fileAttributes = $this->emptyCatalogueStatement();
        $variableSets = $this->emptyCatalogueStatement();
        $variableSetMembers = $this->emptyCatalogueStatement();
        $multipleResponseSets = $this->emptyCatalogueStatement();
        $multipleResponseSetMembers = $this->emptyCatalogueStatement();
        $journal = $this->emptyCatalogueStatement();
        $engine = $this->createMock(SpssEngine::class);

        $pdo->expects(self::exactly(23))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($journal, $dataset, $variables, $cases, $scoreLabels, $scoreMissing, $scoreDisplay, $roleOne, $attributesOne, $commentLabels, $commentMissing, $commentDisplay, $roleTwo, $attributesTwo, $fileLabel, $documents, $fileAttributes, $variableSets, $variableSetMembers, $multipleResponseSets, $multipleResponseSetMembers, $technical, $journal);
        $dataset->expects(self::once())->method('execute')->with(['fixture'])->willReturn(true);
        $dataset->expects(self::once())->method('fetch')->willReturn(['table_name' => 'dataset_fixture']);
        $variables->expects(self::once())->method('execute')->with(['fixture'])->willReturn(true);
        $variables->expects(self::once())->method('fetchAll')->willReturn([
            ['ordinal' => 1, 'source_name' => 'Score', 'column_name' => 'score', 'storage_kind' => 'numeric', 'source_width' => 0, 'format_family' => 5, 'format_width' => 8, 'format_decimals' => 0, 'write_format_family' => 5, 'write_format_width' => 8, 'write_format_decimals' => 0, 'label' => 'Result'],
            ['ordinal' => 2, 'source_name' => 'Comment', 'column_name' => 'comment', 'storage_kind' => 'string', 'source_width' => 12, 'format_family' => 1, 'format_width' => 12, 'format_decimals' => 0, 'write_format_family' => 1, 'write_format_width' => 12, 'write_format_decimals' => 0, 'label' => null],
        ]);
        $cases->expects(self::once())->method('execute')->with()->willReturn(true);
        $cases->expects(self::exactly(3))->method('fetch')->willReturnOnConsecutiveCalls(
            ['score' => '1.5', 'comment' => 'blue'],
            ['score' => null, 'comment' => 'green'],
            false,
        );
        $fileLabel->expects(self::once())->method('execute')->with(['fixture', 'file_label'])->willReturn(true);
        $fileLabel->expects(self::once())->method('fetchColumn')->willReturn('Customer survey source');
        $documents->expects(self::once())->method('execute')->with(['fixture'])->willReturn(true);
        $documents->expects(self::once())->method('fetchAll')->with(PDO::FETCH_COLUMN)->willReturn(['First document line', 'Second document line']);
        $technical->expects(self::once())->method('execute')->with(['fixture'])->willReturn(true);
        $technical->expects(self::once())->method('fetch')->willReturn([
            'source_version' => '31.0',
            'provenance' => 'unit-test',
            'encoding' => 'UTF-8',
            'product_name' => 'SPSS Statistics',
        ]);
        $scoreLabels->expects(self::once())->method('execute')->with(['fixture', 1])->willReturn(true);
        $scoreLabels->expects(self::once())->method('fetch')->willReturn(false);
        $scoreMissing->expects(self::once())->method('execute')->with(['fixture', 1])->willReturn(true);
        $scoreMissing->expects(self::once())->method('fetchColumn')->willReturn(false);
        $scoreDisplay->expects(self::once())->method('execute')->with(['fixture', 1])->willReturn(true);
        $scoreDisplay->expects(self::once())->method('fetch')->willReturn(['measurement_level' => '3', 'display_width' => '15', 'alignment' => '1']);
        $commentLabels->expects(self::once())->method('execute')->with(['fixture', 2])->willReturn(true);
        $commentLabels->expects(self::once())->method('fetch')->willReturn(false);
        $commentMissing->expects(self::once())->method('execute')->with(['fixture', 2])->willReturn(true);
        $commentMissing->expects(self::once())->method('fetchColumn')->willReturn(false);
        $commentDisplay->expects(self::once())->method('execute')->with(['fixture', 2])->willReturn(true);
        $commentDisplay->expects(self::once())->method('fetch')->willReturn(['measurement_level' => '1', 'display_width' => '24', 'alignment' => '0']);
        $engine->expects(self::once())
            ->method('write')
            ->with('fixture.zsav', self::callback(static function (Dataset $written): bool {
                self::assertSame([[1.5, 'blue'], [null, 'green']], $written->rows());
                self::assertSame('Score', $written->variables()[0]->name);
                self::assertSame('Comment', $written->variables()[1]->name);
                self::assertSame(Measure::SCALE, $written->variables()[0]->measure);
                self::assertSame(15, $written->variables()[0]->columns);
                self::assertSame(Alignment::RIGHT, $written->variables()[0]->alignment);
                self::assertSame(Measure::NOMINAL, $written->variables()[1]->measure);
                self::assertSame(24, $written->variables()[1]->columns);
                self::assertSame(Alignment::LEFT, $written->variables()[1]->alignment);
                self::assertSame('Customer survey source', $written->metadata->label);
                self::assertSame(['First document line', 'Second document line'], $written->metadata->documents());
                self::assertSame('zsav', $written->technicalMetadata->sourceFormat);
                self::assertSame('31.0', $written->technicalMetadata->sourceVersion);
                self::assertSame('unit-test', $written->technicalMetadata->provenance);
                self::assertSame('UTF-8', $written->technicalMetadata->encoding);
                self::assertSame('SPSS Statistics', $written->technicalMetadata->productName);

                return true;
            }));

        $result = (new SpssAdapter($pdo, $engine))->export('fixture', 'fixture.zsav');

        self::assertSame(2, $result->caseCount);
        self::assertSame([], $result->diagnostics);
    }

    public function testPostgreSqlExportRestoresOrderedTypedLabelsAndAllUserMissingRuleForms(): void
    {
        $pdo = $this->pdoWithDriver('pgsql');
        $dataset = $this->createMock(PDOStatement::class);
        $variables = $this->createMock(PDOStatement::class);
        $cases = $this->createMock(PDOStatement::class);
        $fileLabel = $this->createMock(PDOStatement::class);
        $documents = $this->createMock(PDOStatement::class);
        $technical = $this->createMock(PDOStatement::class);
        $numericLabels = $this->createMock(PDOStatement::class);
        $numericRule = $this->createMock(PDOStatement::class);
        $numericValues = $this->createMock(PDOStatement::class);
        $numericDisplay = $this->createMock(PDOStatement::class);
        $textLabels = $this->createMock(PDOStatement::class);
        $textRule = $this->createMock(PDOStatement::class);
        $textValues = $this->createMock(PDOStatement::class);
        $textDisplay = $this->createMock(PDOStatement::class);
        $roleOne = $this->emptyCatalogueStatement(0);
        $attributesOne = $this->emptyCatalogueStatement();
        $roleTwo = $this->emptyCatalogueStatement(0);
        $attributesTwo = $this->emptyCatalogueStatement();
        $fileAttributes = $this->emptyCatalogueStatement();
        $variableSets = $this->emptyCatalogueStatement();
        $variableSetMembers = $this->emptyCatalogueStatement();
        $multipleResponseSets = $this->emptyCatalogueStatement();
        $multipleResponseSetMembers = $this->emptyCatalogueStatement();
        $journal = $this->emptyCatalogueStatement();
        $engine = $this->createMock(SpssEngine::class);

        $pdo->expects(self::exactly(25))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls(
                $journal,
                $dataset,
                $variables,
                $cases,
                $numericLabels,
                $numericRule,
                $numericValues,
                $numericDisplay,
                $roleOne,
                $attributesOne,
                $textLabels,
                $textRule,
                $textValues,
                $textDisplay,
                $roleTwo,
                $attributesTwo,
                $fileLabel,
                $documents,
                $fileAttributes,
                $variableSets,
                $variableSetMembers,
                $multipleResponseSets,
                $multipleResponseSetMembers,
                $technical,
                $journal,
            );
        $dataset->expects(self::once())->method('execute')->with(['fixture'])->willReturn(true);
        $dataset->expects(self::once())->method('fetch')->willReturn(['table_name' => 'dataset_fixture']);
        $variables->expects(self::once())->method('execute')->with(['fixture'])->willReturn(true);
        $variables->expects(self::once())->method('fetchAll')->willReturn([
            ['ordinal' => '1', 'source_name' => 'Score', 'column_name' => 'score', 'storage_kind' => 'numeric', 'source_width' => '0', 'format_family' => '5', 'format_width' => '8', 'format_decimals' => '0', 'write_format_family' => '5', 'write_format_width' => '8', 'write_format_decimals' => '0', 'label' => null],
            ['ordinal' => '2', 'source_name' => 'Reason', 'column_name' => 'reason', 'storage_kind' => 'string', 'source_width' => '20', 'format_family' => '1', 'format_width' => '20', 'format_decimals' => '0', 'write_format_family' => '1', 'write_format_width' => '20', 'write_format_decimals' => '0', 'label' => 'Reason label'],
        ]);
        $cases->expects(self::once())->method('execute')->with()->willReturn(true);
        $cases->expects(self::exactly(2))->method('fetch')->willReturnOnConsecutiveCalls(['score' => '2', 'reason' => 'MISSING'], false);

        $fileLabel->expects(self::once())->method('execute')->with(['fixture', 'file_label'])->willReturn(true);
        $fileLabel->expects(self::once())->method('fetchColumn')->willReturn(false);
        $documents->expects(self::once())->method('execute')->with(['fixture'])->willReturn(true);
        $documents->expects(self::once())->method('fetchAll')->with(PDO::FETCH_COLUMN)->willReturn([]);
        $technical->expects(self::once())->method('execute')->with(['fixture'])->willReturn(true);
        $technical->expects(self::once())->method('fetch')->willReturn(false);

        $numericLabels->expects(self::once())->method('execute')->with(['fixture', 1])->willReturn(true);
        $numericLabels->expects(self::exactly(3))->method('fetch')->willReturnOnConsecutiveCalls(
            ['value_kind' => 'numeric', 'numeric_value' => '2', 'text_value' => null, 'label' => 'No'],
            ['value_kind' => 'numeric', 'numeric_value' => '1', 'text_value' => null, 'label' => 'Yes'],
            false,
        );
        $numericRule->expects(self::once())->method('execute')->with(['fixture', 1])->willReturn(true);
        $numericRule->expects(self::once())->method('fetchColumn')->willReturn('-3');
        $numericValues->expects(self::once())->method('execute')->with(['fixture', 1])->willReturn(true);
        $numericValues->expects(self::exactly(4))->method('fetch')->willReturnOnConsecutiveCalls(
            ['value_kind' => 'numeric', 'numeric_value' => '10', 'text_value' => null],
            ['value_kind' => 'numeric', 'numeric_value' => '20', 'text_value' => null],
            ['value_kind' => 'numeric', 'numeric_value' => '99', 'text_value' => null],
            false,
        );
        $numericDisplay->expects(self::once())->method('execute')->with(['fixture', 1])->willReturn(true);
        $numericDisplay->expects(self::once())->method('fetch')->willReturn(false);

        $textLabels->expects(self::once())->method('execute')->with(['fixture', 2])->willReturn(true);
        $textLabels->expects(self::exactly(3))->method('fetch')->willReturnOnConsecutiveCalls(
            ['value_kind' => 'text', 'numeric_value' => null, 'text_value' => 'MISSING', 'label' => 'Not answered'],
            ['value_kind' => 'text', 'numeric_value' => null, 'text_value' => 'REFUSED', 'label' => 'Refused'],
            false,
        );
        $textRule->expects(self::once())->method('execute')->with(['fixture', 2])->willReturn(true);
        $textRule->expects(self::once())->method('fetchColumn')->willReturn(2);
        $textValues->expects(self::once())->method('execute')->with(['fixture', 2])->willReturn(true);
        $textValues->expects(self::exactly(3))->method('fetch')->willReturnOnConsecutiveCalls(
            ['value_kind' => 'text', 'numeric_value' => null, 'text_value' => 'MISSING'],
            ['value_kind' => 'text', 'numeric_value' => null, 'text_value' => 'REFUSED'],
            false,
        );
        $textDisplay->expects(self::once())->method('execute')->with(['fixture', 2])->willReturn(true);
        $textDisplay->expects(self::once())->method('fetch')->willReturn(false);

        $engine->expects(self::once())
            ->method('write')
            ->with('fixture.sav', self::callback(static function (Dataset $written): bool {
                $variables = $written->variables();
                self::assertEquals([
                    new ValueLabel(2.0, 'No'),
                    new ValueLabel(1.0, 'Yes'),
                ], $variables[0]->valueLabels->labels());
                self::assertEquals(MissingValues::rangeAndValue(10.0, 20.0, 99.0), $variables[0]->missingValues);
                self::assertEquals([
                    new ValueLabel('MISSING', 'Not answered'),
                    new ValueLabel('REFUSED', 'Refused'),
                ], $variables[1]->valueLabels->labels());
                self::assertEquals(MissingValues::discrete('MISSING', 'REFUSED'), $variables[1]->missingValues);
                self::assertNull($written->metadata->label);
                self::assertSame([], $written->metadata->documents());
                self::assertSame('UTF-8', $written->technicalMetadata->encoding);

                return true;
            }));

        $result = (new SpssAdapter($pdo, $engine))->export('fixture', 'fixture.sav');

        self::assertSame(1, $result->caseCount);
        self::assertSame([], $result->diagnostics);
    }

    private function emptyCatalogueStatement(int|bool $column = false): PDOStatement
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn(false);
        $statement->method('fetchColumn')->willReturn($column);

        return $statement;
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
