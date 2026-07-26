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
use SPSS\Sav\FileAttribute;
use SPSS\Sav\MultipleResponseCategoryLabels;
use SPSS\Sav\MultipleResponseLabelSource;
use SPSS\Sav\MultipleResponseSet;
use SPSS\Sav\MultipleResponseSetType;
use SPSS\Sav\Measure;
use SPSS\Sav\MissingValues;
use SPSS\Sav\ValueLabel;
use SPSS\Sav\ValueLabelSet;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableAttribute;
use SPSS\Sav\VariableFormat;
use SPSS\Sav\VariableMetadata;
use SPSS\Sav\VariableRole;
use SPSS\Sav\VariableSet;
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

        self::assertSame(
            [
                ['variable_ordinal' => 1, 'role' => 1],
                ['variable_ordinal' => 2, 'role' => 0],
            ],
            self::rows($pdo, 'SELECT variable_ordinal, role FROM variable_roles WHERE dataset_name = "Customer survey" ORDER BY variable_ordinal'),
        );
        self::assertSame(
            [
                ['attribute_name' => 'Data source', 'ordinal' => 1, 'value' => 'CRM'],
                ['attribute_name' => 'Data source', 'ordinal' => 2, 'value' => 'verified'],
            ],
            self::rows($pdo, 'SELECT attribute_name, ordinal, value FROM file_attributes WHERE dataset_name = "Customer survey" ORDER BY attribute_name, ordinal'),
        );
        self::assertSame(
            [
                ['variable_ordinal' => 1, 'attribute_name' => 'Origin', 'ordinal' => 1, 'value' => 'customer'],
                ['variable_ordinal' => 1, 'attribute_name' => 'Origin', 'ordinal' => 2, 'value' => 'identifier'],
                ['variable_ordinal' => 2, 'attribute_name' => 'Presentation', 'ordinal' => 1, 'value' => 'question'],
            ],
            self::rows($pdo, 'SELECT variable_ordinal, attribute_name, ordinal, value FROM variable_attributes WHERE dataset_name = "Customer survey" ORDER BY variable_ordinal, attribute_name, ordinal'),
        );
        self::assertSame(
            [
                ['name' => 'Core', 'member_ordinal' => 1, 'variable_ordinal' => 1],
                ['name' => 'Core', 'member_ordinal' => 2, 'variable_ordinal' => 2],
            ],
            self::rows($pdo, 'SELECT set_table.name, member.member_ordinal, member.variable_ordinal FROM variable_sets set_table JOIN variable_set_members member ON member.dataset_name = set_table.dataset_name AND member.set_ordinal = set_table.set_ordinal WHERE set_table.dataset_name = "Customer survey" ORDER BY set_table.set_ordinal, member.member_ordinal'),
        );
        self::assertSame(
            [
                ['name' => '$Colour', 'set_type' => 'dichotomy', 'counted_value_kind' => 'text', 'counted_text_value' => 'yes', 'category_labels' => 'counted_values', 'label_source' => 'variable_label', 'member_ordinal' => 1, 'variable_ordinal' => 2],
                ['name' => '$Profile', 'set_type' => 'category', 'counted_value_kind' => null, 'counted_text_value' => null, 'category_labels' => 'variable_labels', 'label_source' => 'set_label', 'member_ordinal' => 1, 'variable_ordinal' => 1],
                ['name' => '$Profile', 'set_type' => 'category', 'counted_value_kind' => null, 'counted_text_value' => null, 'category_labels' => 'variable_labels', 'label_source' => 'set_label', 'member_ordinal' => 2, 'variable_ordinal' => 2],
            ],
            self::rows($pdo, 'SELECT set_table.name, set_table.set_type, set_table.counted_value_kind, set_table.counted_text_value, set_table.category_labels, set_table.label_source, member.member_ordinal, member.variable_ordinal FROM multiple_response_sets set_table JOIN multiple_response_set_members member ON member.dataset_name = set_table.dataset_name AND member.set_ordinal = set_table.set_ordinal WHERE set_table.dataset_name = "Customer survey" ORDER BY set_table.set_ordinal, member.member_ordinal'),
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
                    role: VariableRole::TARGET,
                    attributes: [new VariableAttribute('Respondent ID', 'Origin', ['customer', 'identifier'])],
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
                    role: VariableRole::INPUT,
                    attributes: [new VariableAttribute('Favourite colour', 'Presentation', ['question'])],
                    dictionaryIndex: 2,
                ),
            ]),
            [[7.0, 'blue'], [8.0, 'green']],
            new FileMetadata(
                'Customer survey source',
                documents: ['First document line', 'Second document line'],
                attributes: [new FileAttribute('Data source', ['CRM', 'verified'])],
                variableSets: [new VariableSet('Core', ['Respondent ID', 'Favourite colour'])],
                multipleResponseSets: [
                    new MultipleResponseSet(
                        '$Colour',
                        MultipleResponseSetType::DICHOTOMY,
                        ['Favourite colour'],
                        'Selected colours',
                        'yes',
                        MultipleResponseCategoryLabels::COUNTED_VALUES,
                        MultipleResponseLabelSource::VARIABLE_LABEL,
                    ),
                    new MultipleResponseSet('$Profile', MultipleResponseSetType::CATEGORY, ['Respondent ID', 'Favourite colour'], 'Profile'),
                ],
            ),
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
