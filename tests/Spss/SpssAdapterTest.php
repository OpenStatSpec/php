<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Spss;

use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\PhpSpssEngine;
use OpenStatSpec\Spss\SpssAdapter;
use OpenStatSpec\Tests\Support\FakeSpssEngine;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SPSS\Sav\Alignment;
use SPSS\Sav\Dataset;
use SPSS\Sav\FileMetadata;
use SPSS\Sav\Measure;
use SPSS\Sav\MissingValues;
use SPSS\Sav\ValueLabel;
use SPSS\Sav\ValueLabelSet;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableFormat;
use SPSS\Sav\VariableMetadata;
use SPSS\Sav\VariableType;

final class SpssAdapterTest extends TestCase
{
    public function testComposerInstallsTheOfficialPhpSpssV3Engine(): void
    {
        self::assertTrue((new PhpSpssEngine())->isAvailable());
        self::assertTrue(class_exists(Dataset::class));
    }

    public function testTypedDatasetImportCreatesWideTableAndExportPreservesSupportedMetadata(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:');
        $engine = new FakeSpssEngine($this->fixture());
        $adapter = new SpssAdapter($pdo, $engine);

        $adapter->import('fixture.sav', 'Customer survey');

        self::assertSame(
            [
                ['__case_ordinal' => 1, 'respondent_id' => 7.0, 'favourite_colour' => 'blue'],
                ['__case_ordinal' => 2, 'respondent_id' => 8.0, 'favourite_colour' => 'green'],
            ],
            self::rows($pdo, 'SELECT "__case_ordinal", "respondent_id", "favourite_colour" FROM "dataset_customer_survey" ORDER BY "__case_ordinal"'),
        );
        self::assertSame(
            [
                ['ordinal' => 1, 'source_name' => 'Respondent ID', 'column_name' => 'respondent_id', 'storage_kind' => 'numeric', 'source_width' => 0, 'format_family' => 5, 'format_width' => 8, 'format_decimals' => 0],
                ['ordinal' => 2, 'source_name' => 'Favourite colour', 'column_name' => 'favourite_colour', 'storage_kind' => 'string', 'source_width' => 12, 'format_family' => 1, 'format_width' => 12, 'format_decimals' => 0],
            ],
            self::rows($pdo, 'SELECT ordinal, source_name, column_name, storage_kind, source_width, format_family, format_width, format_decimals FROM variables WHERE dataset_name = "Customer survey" ORDER BY ordinal'),
        );

        $result = $adapter->export('Customer survey', 'roundtrip.sav');
        $written = $engine->lastWrite()['dataset'];

        self::assertSame([], $result->diagnostics);
        self::assertSame('Customer survey source', $written->metadata->label);
        self::assertSame(['First document line', 'Second document line'], $written->metadata->documents());
        self::assertSame([[7.0, 'blue'], [8.0, 'green']], $written->rows());

        $first = $written->variables()[0];
        self::assertSame('Respondent ID', $first->name);
        self::assertSame('Respondent identifier', $first->label);
        self::assertEquals([new ValueLabel(7.0, 'Seven')], $first->valueLabels->labels());
        self::assertEquals(MissingValues::range(1.0, 3.0), $first->missingValues);
        self::assertSame(Measure::SCALE, $first->measure);
        self::assertSame(Alignment::RIGHT, $first->alignment);
        self::assertSame(10, $first->columns);
    }

    public function testPhpSpssEngineWritesAndReadsTypedDataset(): void
    {
        $target = sys_get_temp_dir() . '/openstatspec-v3-' . uniqid('', true) . '.sav';
        try {
            $engine = new PhpSpssEngine();
            $engine->write($target, $this->engineFixture());
            $readBack = $engine->read($target);

            self::assertSame($this->engineFixture()->rows(), $readBack->rows());
            self::assertSame('Customer survey source', $readBack->metadata->label);
            self::assertSame(['First document line', 'Second document line'], $readBack->metadata->documents());
            self::assertSame('Respondent_ID', $readBack->variables()[0]->name);
            self::assertEquals([new ValueLabel(7.0, 'Seven')], $readBack->variables()[0]->valueLabels->labels());
            self::assertEquals(MissingValues::range(1.0, 3.0), $readBack->variables()[0]->missingValues);
        } finally {
            @unlink($target);
        }
    }

    public function testImportRejectsZsavBeforeReadingOrChangingTheDatabase(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:');
        $adapter = new SpssAdapter($pdo, new FakeSpssEngine($this->fixture()));

        $this->expectException(UnsupportedOperation::class);
        $this->expectExceptionMessage('ZSAV import is not supported');

        try {
            $adapter->import('fixture.zsav', 'fixture');
        } finally {
            self::assertSame([], self::rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table'"));
        }
    }

    private function fixture(): Dataset
    {
        return new Dataset(
            new VariableDictionary([
                new VariableMetadata(
                    name: 'Respondent ID',
                    type: VariableType::NUMERIC,
                    width: 0,
                    printFormat: new VariableFormat(5, 8),
                    writeFormat: new VariableFormat(5, 8),
                    label: 'Respondent identifier',
                    valueLabels: new ValueLabelSet([new ValueLabel(7.0, 'Seven')], ['Respondent ID']),
                    missingValues: MissingValues::range(1.0, 3.0),
                    measure: Measure::SCALE,
                    alignment: Alignment::RIGHT,
                    columns: 10,
                    dictionaryIndex: 1,
                ),
                new VariableMetadata(
                    name: 'Favourite colour',
                    type: VariableType::STRING,
                    width: 12,
                    printFormat: new VariableFormat(1, 12),
                    writeFormat: new VariableFormat(1, 12),
                    label: 'Favourite colour',
                    measure: Measure::NOMINAL,
                    alignment: Alignment::LEFT,
                    columns: 12,
                    dictionaryIndex: 2,
                ),
            ]),
            [[7.0, 'blue'], [8.0, 'green']],
            new FileMetadata('Customer survey source', documents: ['First document line', 'Second document line']),
        );
    }

    private function engineFixture(): Dataset
    {
        return new Dataset(
            new VariableDictionary([
                new VariableMetadata(
                    name: 'Respondent_ID',
                    type: VariableType::NUMERIC,
                    width: 0,
                    printFormat: new VariableFormat(5, 8),
                    writeFormat: new VariableFormat(5, 8),
                    label: 'Respondent identifier',
                    valueLabels: new ValueLabelSet([new ValueLabel(7.0, 'Seven')], ['Respondent_ID']),
                    missingValues: MissingValues::range(1.0, 3.0),
                    measure: Measure::SCALE,
                    alignment: Alignment::RIGHT,
                    columns: 10,
                    dictionaryIndex: 1,
                ),
                new VariableMetadata(
                    name: 'Favourite_colour',
                    type: VariableType::STRING,
                    width: 12,
                    printFormat: new VariableFormat(1, 12),
                    writeFormat: new VariableFormat(1, 12),
                    label: 'Favourite colour',
                    measure: Measure::NOMINAL,
                    alignment: Alignment::LEFT,
                    columns: 12,
                    dictionaryIndex: 2,
                ),
            ]),
            [[7.0, 'blue'], [8.0, 'green']],
            new FileMetadata('Customer survey source', documents: ['First document line', 'Second document line']),
        );
    }

    /** @return list<array<string, mixed>> */
    private static function rows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Test query failed.');
        }

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
