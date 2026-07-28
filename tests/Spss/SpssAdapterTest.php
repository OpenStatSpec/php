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
use SPSS\Sav\FileTechnicalMetadata;
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
        self::assertSame(["package" => "tiamo/spss", "version" => "3.0.0"], (new PhpSpssEngine())->identity());
    }

    public function testTypedDatasetImportCreatesWideTableAndExportPreservesSupportedMetadata(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:');
        $engine = new FakeSpssEngine($this->fixture());
        $adapter = new SpssAdapter($pdo, $engine);

        $import = $adapter->import('fixture.sav', 'Customer survey');

        self::assertSame('Customer survey', $import->datasetName);
        self::assertSame(2, $import->caseCount);
        self::assertSame([], $import->diagnostics);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $import->operationId);
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
            [['variable_ordinal' => 1]],
            self::rows($pdo, 'SELECT variable_ordinal FROM dataset_weight_variables WHERE dataset_name = "Customer survey"'),
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
        self::assertSame([], $result->allowLoss);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $result->operationId);
        self::assertSame(
            [
                ['direction' => 'import', 'status' => 'succeeded', 'dataset_name' => 'Customer survey', 'target_path' => 'fixture.sav', 'allow_loss' => '[]', 'failure_code' => null],
                ['direction' => 'export', 'status' => 'succeeded', 'dataset_name' => 'Customer survey', 'target_path' => 'roundtrip.sav', 'allow_loss' => '[]', 'failure_code' => null],
            ],
            self::rows($pdo, 'SELECT direction, status, dataset_name, target_path, allow_loss, failure_code FROM operation_catalog ORDER BY rowid'),
        );
        self::assertSame(["{\"package\":\"fake-spss-engine\",\"version\":\"test\"}", "{\"package\":\"fake-spss-engine\",\"version\":\"test\"}"], array_column(self::rows($pdo, 'SELECT engine_details FROM operation_catalog ORDER BY rowid'), 'engine_details'));
        self::assertSame('Customer survey source', $written->metadata->label);
        self::assertSame('Respondent ID', $written->metadata->weightVariableName);
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
        self::assertSame(VariableRole::TARGET, $first->role);
        self::assertEquals(
            [new VariableAttribute('Respondent ID', 'Origin', ['customer', 'identifier'])],
            $first->attributes(),
        );
        self::assertSame(VariableRole::INPUT, $written->variables()[1]->role);
        self::assertEquals(
            [new FileAttribute('Data source', ['CRM', 'verified'])],
            $written->metadata->attributes(),
        );
        self::assertEquals(
            [new VariableSet('Core', ['Respondent ID', 'Favourite colour'])],
            $written->metadata->variableSets(),
        );
        $multipleResponseSets = $written->metadata->multipleResponseSets();
        self::assertCount(2, $multipleResponseSets);
        self::assertSame('$Colour', $multipleResponseSets[0]->name);
        self::assertSame(MultipleResponseSetType::DICHOTOMY, $multipleResponseSets[0]->type);
        self::assertSame(['Favourite colour'], $multipleResponseSets[0]->variableNames());
        self::assertSame('Selected colours', $multipleResponseSets[0]->label);
        self::assertSame('yes', $multipleResponseSets[0]->countedValue);
        self::assertSame(MultipleResponseCategoryLabels::COUNTED_VALUES, $multipleResponseSets[0]->categoryLabels);
        self::assertSame(MultipleResponseLabelSource::VARIABLE_LABEL, $multipleResponseSets[0]->labelSource);
        self::assertSame('$Profile', $multipleResponseSets[1]->name);
        self::assertSame(MultipleResponseSetType::CATEGORY, $multipleResponseSets[1]->type);
        self::assertSame(['Respondent ID', 'Favourite colour'], $multipleResponseSets[1]->variableNames());
        self::assertNull($multipleResponseSets[1]->countedValue);
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
            self::assertSame('Respondent_ID', $readBack->metadata->weightVariableName);
            self::assertSame(['First document line', 'Second document line'], $readBack->metadata->documents());
            self::assertSame('Respondent_ID', $readBack->variables()[0]->name);
            self::assertEquals([new ValueLabel(7.0, 'Seven')], $readBack->variables()[0]->valueLabels->labels());
            self::assertEquals(MissingValues::range(1.0, 3.0), $readBack->variables()[0]->missingValues);
            self::assertSame(VariableRole::TARGET, $readBack->variables()[0]->role);
            self::assertEquals([new VariableAttribute('Respondent_ID', 'Origin', ['engine'])], $readBack->variables()[0]->attributes());
        } finally {
            @unlink($target);
        }
    }

    public function testFailedImportPreflightIsRecordedWithoutCreatingTheDataset(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:');
        $adapter = new SpssAdapter($pdo, new FakeSpssEngine($this->fixture()));

        try {
            $adapter->import('fixture.por', 'not_created');
            self::fail('POR preflight must fail.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame('unsupported_source_format', $exception->diagnosticCode->value);
        }

        self::assertSame(
            [[
                'direction' => 'import',
                'status' => 'failed',
                'dataset_name' => null,
                'target_path' => 'fixture.por',
                'failure_code' => 'unsupported_source_format',
            ]],
            self::rows($pdo, 'SELECT direction, status, dataset_name, target_path, failure_code FROM operation_catalog'),
        );
        self::assertSame(
            [],
            self::rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'dataset_not_created'"),
        );
        self::assertSame(
            [[
                'operation_kind' => 'import',
                'status' => 'failed',
                'source_format' => 'por',
                'has_started_at' => 1,
                'has_completed_at' => 1,
            ]],
            self::rows($pdo, 'SELECT operation_kind, status, source_format, started_at IS NOT NULL AS has_started_at, completed_at IS NOT NULL AS has_completed_at FROM operation'),
        );
        self::assertSame(
            [[
                'direction' => 'import',
                'severity' => 'error',
                'event_code' => 'unsupported_source_format',
                'source_item' => 'fixture.por',
                'dataset_is_null' => 1,
                'has_created_at' => 1,
            ]],
            self::rows($pdo, 'SELECT direction, severity, event_code, source_item, dataset_id IS NULL AS dataset_is_null, created_at IS NOT NULL AS has_created_at FROM fidelity_event'),
        );
    }

    public function testImportAllowsZsav(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:');
        $adapter = new SpssAdapter($pdo, new FakeSpssEngine($this->fixture()));

        $adapter->import('fixture.zsav', 'fixture');

        self::assertSame(
            [['name' => 'dataset_fixture']],
            self::rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'dataset_fixture'"),
        );
    }

    public function testRealEngineRoundTripsSavAndZsavLongUtf8StringsAndAllMissingRules(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $engine = new PhpSpssEngine();
        foreach (['sav' => ['$FL2', 1], 'zsav' => ['$FL3', 2]] as $format => [$header, $compression]) {
            $source = sys_get_temp_dir() . '/openstatspec-v3-source-' . uniqid('', true) . '.' . $format;
            $target = sys_get_temp_dir() . '/openstatspec-v3-target-' . uniqid('', true) . '.' . $format;
            try {
                $fixture = $this->longStringAndMissingValuesFixture($format);
                $engine->write($source, $fixture);
                self::assertSame($header, self::fileHeader($source));
                self::assertSame($format, $engine->read($source)->technicalMetadata->sourceFormat);
                self::assertSame($compression, $engine->read($source)->technicalMetadata->compression);

                $adapter = new SpssAdapter(new PDO('sqlite::memory:'), $engine);
                $adapter->import($source, 'roundtrip_' . $format);
                $result = $adapter->export('roundtrip_' . $format, $target);

                self::assertSame([], $result->diagnostics);
                self::assertSame($header, self::fileHeader($target));

                $readBack = $engine->read($target);
                self::assertSame($format, $readBack->technicalMetadata->sourceFormat);
                self::assertSame($compression, $readBack->technicalMetadata->compression);
                self::assertSame($fixture->rows(), $readBack->rows());
                self::assertSame('No_missing', $readBack->metadata->weightVariableName);
                self::assertSame(400, $readBack->variables()[4]->width);
                $longString = $readBack->rows()[0][4];
                self::assertIsString($longString);
                self::assertSame(340, strlen($longString));
                self::assertEquals(MissingValues::none(), $readBack->variables()[0]->missingValues);
                self::assertEquals(MissingValues::discrete(-1.0, -2.0), $readBack->variables()[1]->missingValues);
                self::assertEquals(MissingValues::range(1.0, 3.0), $readBack->variables()[2]->missingValues);
                self::assertEquals(MissingValues::rangeAndValue(10.0, 20.0, 99.0), $readBack->variables()[3]->missingValues);
            } finally {
                @unlink($source);
                @unlink($target);
            }
        }
    }

    public function testImportRejectsStringMissingValueRanges(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $source = new Dataset(
            new VariableDictionary([
                new VariableMetadata(
                    name: 'Text_value',
                    type: VariableType::STRING,
                    width: 12,
                    printFormat: new VariableFormat(1, 12),
                    writeFormat: new VariableFormat(1, 12),
                    missingValues: MissingValues::range(1.0, 2.0),
                    dictionaryIndex: 1,
                ),
            ]),
            [['present']],
        );
        $adapter = new SpssAdapter(new PDO('sqlite::memory:'), new FakeSpssEngine($source));

        $this->expectException(UnsupportedOperation::class);
        $this->expectExceptionMessage('string variables may have discrete user-missing values only');

        $adapter->import('malformed.sav', 'malformed');
    }

    public function testTechnicalMetadataIsCataloguedOnImport(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:');
        $adapter = new SpssAdapter($pdo, new FakeSpssEngine($this->fixture()));

        $adapter->import('fixture.zsav', 'technical fixture');

        self::assertSame(
            [[
                'source_format' => 'zsav',
                'record_type' => '$FL3',
                'source_version' => 'OpenStatSpec 0.1',
                'provenance' => 'Päritolu: küsitlus',
                'encoding' => 'UTF-8',
                'product_name' => 'OpenStatSpec tööriist',
                'raw_creation_date' => '26 JUL 26',
                'raw_creation_time' => '12:34:56',
                'case_count' => 2,
                'nominal_case_size' => 2,
                'layout_code' => 2,
                'compression' => 2,
                'compression_bias' => 100.0,
                'machine_code' => 1,
                'floating_point_representation' => 1,
                'endianness' => 2,
                'character_code' => 65001,
            ]],
            self::rows($pdo, 'SELECT source_format, record_type, source_version, provenance, encoding, product_name, raw_creation_date, raw_creation_time, case_count, nominal_case_size, layout_code, compression, compression_bias, machine_code, floating_point_representation, endianness, character_code FROM file_technical_metadata WHERE dataset_name = "technical fixture"'),
        );
    }

    public function testExportRestoresCataloguedTechnicalMetadataWhileTargetDeterminesContainer(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $engine = new FakeSpssEngine($this->fixture());
        $adapter = new SpssAdapter(new PDO('sqlite::memory:'), $engine);
        $adapter->import('fixture.zsav', 'technical fixture');
        $adapter->export('technical fixture', 'technical-roundtrip.sav');

        $technical = $engine->lastWrite()['dataset']->technicalMetadata;
        self::assertSame('sav', $technical->sourceFormat);
        self::assertSame('$FL2', $technical->recordType);
        self::assertSame(1, $technical->compression);
        self::assertSame('OpenStatSpec 0.1', $technical->sourceVersion);
        self::assertSame("P\u{00E4}ritolu: k\u{00FC}sitlus", $technical->provenance);
        self::assertSame('UTF-8', $technical->encoding);
        self::assertSame("OpenStatSpec t\u{00F6}\u{00F6}riist", $technical->productName);
    }

    public function testMigrateCatalogBackfillsLegacyDatasetsAndIsIdempotent(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:');
        $engine = new FakeSpssEngine($this->fixture());
        (new SpssAdapter($pdo, $engine, false))->import('legacy-fixture.sav', 'Legacy survey');

        self::assertSame([], self::rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'dataset'"));

        $adapter = new SpssAdapter($pdo, $engine);
        $adapter->migrateCatalog();
        $adapter->migrateCatalog();

        self::assertSame(
            [[
                'spec_version' => '1.0',
                'source_format' => 'zsav',
                'physical_table_name' => 'dataset_legacy_survey',
                'dataset_name' => 'Legacy survey',
                'source_case_count' => 2,
            ]],
            self::rows($pdo, 'SELECT spec_version, source_format, physical_table_name, dataset_name, source_case_count FROM dataset'),
        );
        self::assertSame(
            [['version' => 1]],
            self::rows($pdo, 'SELECT version FROM openstatspec_schema_migration ORDER BY version'),
        );
        self::assertSame(
            [['variable_count' => 2]],
            self::rows($pdo, 'SELECT COUNT(*) AS variable_count FROM variable'),
        );

        $export = $adapter->export('Legacy survey', 'legacy-roundtrip.sav');
        self::assertSame(2, $export->caseCount);
        self::assertSame([[7.0, 'blue'], [8.0, 'green']], $engine->lastWrite()['dataset']->rows());
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
                weightVariableName: 'Respondent ID',
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
            new FileTechnicalMetadata(
                sourceFormat: 'zsav',
                recordType: '$FL3',
                sourceVersion: 'OpenStatSpec 0.1',
                provenance: 'Päritolu: küsitlus',
                encoding: 'UTF-8',
                productName: 'OpenStatSpec tööriist',
                rawCreationDate: '26 JUL 26',
                rawCreationTime: '12:34:56',
                caseCount: 2,
                nominalCaseSize: 2,
                layoutCode: 2,
                compression: 2,
                compressionBias: 100.0,
                machineCode: 1,
                floatingPointRepresentation: 1,
                endianness: 2,
                characterCode: 65001,
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
                    role: VariableRole::TARGET,
                    attributes: [new VariableAttribute('Respondent_ID', 'Origin', ['engine'])],
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
            new FileMetadata('Customer survey source', weightVariableName: 'Respondent_ID', documents: ['First document line', 'Second document line']),
        );
    }

    private function longStringAndMissingValuesFixture(string $format): Dataset
    {
        $longValue = str_repeat("\xC3\xB5", 170);

        return new Dataset(
            new VariableDictionary([
                new VariableMetadata(
                    name: 'No_missing',
                    type: VariableType::NUMERIC,
                    width: 0,
                    printFormat: new VariableFormat(5, 8),
                    writeFormat: new VariableFormat(5, 8),
                    missingValues: MissingValues::none(),
                    dictionaryIndex: 1,
                ),
                new VariableMetadata(
                    name: 'Discrete_missing',
                    type: VariableType::NUMERIC,
                    width: 0,
                    printFormat: new VariableFormat(5, 8),
                    writeFormat: new VariableFormat(5, 8),
                    missingValues: MissingValues::discrete(-1.0, -2.0),
                    dictionaryIndex: 2,
                ),
                new VariableMetadata(
                    name: 'Range_missing',
                    type: VariableType::NUMERIC,
                    width: 0,
                    printFormat: new VariableFormat(5, 8),
                    writeFormat: new VariableFormat(5, 8),
                    missingValues: MissingValues::range(1.0, 3.0),
                    dictionaryIndex: 3,
                ),
                new VariableMetadata(
                    name: 'Range_and_value_missing',
                    type: VariableType::NUMERIC,
                    width: 0,
                    printFormat: new VariableFormat(5, 8),
                    writeFormat: new VariableFormat(5, 8),
                    missingValues: MissingValues::rangeAndValue(10.0, 20.0, 99.0),
                    dictionaryIndex: 4,
                ),
                new VariableMetadata(
                    name: 'Long_utf8',
                    type: VariableType::STRING,
                    width: 400,
                    printFormat: new VariableFormat(1, 255),
                    writeFormat: new VariableFormat(1, 255),
                    missingValues: MissingValues::discrete('MISSING'),
                    dictionaryIndex: 5,
                ),
            ]),
            [[10.0, 2.0, 4.0, 9.0, $longValue]],
            new FileMetadata('Long UTF-8 and missing-values fixture', weightVariableName: 'No_missing'),
            new FileTechnicalMetadata(
                sourceFormat: $format,
                compression: $format === 'zsav' ? 2 : 1,
            ),
        );
    }

    private static function fileHeader(string $path): string
    {
        $header = file_get_contents($path, false, null, 0, 4);
        if (!is_string($header)) {
            throw new RuntimeException('Could not read the SPSS file header.');
        }

        return $header;
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
