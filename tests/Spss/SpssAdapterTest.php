<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\GuardedImportSpssEngine;
use OpenStatSpec\Spss\PhpSpssEngine;
use OpenStatSpec\Spss\SpssAdapter;
use OpenStatSpec\Spss\SpssSourceNormalizer;
use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\CanonicalWideTableExporter;
use OpenStatSpec\Sql\SqliteWideTableExporter;
use OpenStatSpec\Sql\MySqlWideTableExporter;
use OpenStatSpec\Sql\PostgreSqlWideTableExporter;
use OpenStatSpec\Tests\Support\ExportCountingPdo;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Transformation\Execution\InPlaceApplyRequest;
use OpenStatSpec\Transformation\Execution\InPlaceTransformationExecutor;
use OpenStatSpec\Transformation\Plan\PlanCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Sql\SqliteWideTableImporter;
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
        self::assertSame([
            'package' => 'openstatspec/spss-sav',
            'version' => '3.1.1',
            'active_version' => '3.1.1',
            'claimed_version_range' => '>=3.0.0 <4.0.0',
            'ci_tested_versions' => ['3.1.1'],
            'claimed_supported' => true,
        ], (new PhpSpssEngine())->identity());
    }

    public function testSuccessfulMigrationAdvancesOlderIdentityVersion(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        CatalogOwnership::ensure($pdo);
        $pdo->exec('UPDATE catalog_identity SET schema_version = 1');

        (new SpssAdapter($pdo, new FakeSpssEngine($this->fixture())))->migrateCatalog();

        self::assertSame([['schema_version' => 4]], self::rows($pdo, 'SELECT schema_version FROM catalog_identity'));
    }

    public function testFailedMigrationDoesNotAdvanceOlderIdentityVersion(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        CatalogOwnership::ensure($pdo);
        $pdo->exec('UPDATE catalog_identity SET schema_version = 1');
        $pdo->exec('CREATE TABLE datasets (application_id INTEGER PRIMARY KEY)');

        try {
            (new SpssAdapter($pdo, new FakeSpssEngine($this->fixture())))->migrateCatalog();
            self::fail('A malformed legacy catalogue unexpectedly migrated.');
        } catch (\PDOException|UnsupportedOperation) {
            self::assertSame([['schema_version' => 1]], self::rows($pdo, 'SELECT schema_version FROM catalog_identity'));
        }
    }

    public function testImportRequiresExplicitMigrationForOlderIdentityWithoutJournalMutation(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        CatalogOwnership::ensure($pdo);
        (new NormativeCatalog($pdo))->createTables();
        $pdo->exec('UPDATE catalog_identity SET schema_version = 1');
        $adapter = new SpssAdapter($pdo, new FakeSpssEngine($this->fixture()));

        try {
            $adapter->import('fixture.sav', 'Old catalog import');
            self::fail('Import used an older catalogue without explicit migration.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::CatalogMigrationRequired, $exception->diagnosticCode);
        }

        self::assertSame([['schema_version' => 1]], self::rows($pdo, 'SELECT schema_version FROM catalog_identity'));
        self::assertSame([], self::rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'operation_catalog'"));

        $adapter->migrateCatalog();
        $result = $adapter->import('fixture.sav', 'Old catalog import');

        self::assertSame(2, $result->caseCount);
        self::assertSame([['schema_version' => 4]], self::rows($pdo, 'SELECT schema_version FROM catalog_identity'));
    }

    public function testExportRequiresExplicitMigrationForOlderIdentityWithoutJournalMutation(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $adapter = new SpssAdapter($pdo, new FakeSpssEngine($this->fixture()));
        $adapter->import('fixture.sav', 'Old catalog export');
        $pdo->exec('UPDATE catalog_identity SET schema_version = 1');
        $before = self::rows($pdo, 'SELECT COUNT(*) AS operation_count FROM operation_catalog');

        try {
            $adapter->export('Old catalog export', 'old-catalog.sav');
            self::fail('Export used an older catalogue without explicit migration.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::CatalogMigrationRequired, $exception->diagnosticCode);
        }

        self::assertSame($before, self::rows($pdo, 'SELECT COUNT(*) AS operation_count FROM operation_catalog'));
        self::assertSame([['schema_version' => 1]], self::rows($pdo, 'SELECT schema_version FROM catalog_identity'));

        $adapter->migrateCatalog();
        $target = sys_get_temp_dir() . '/oss-' . bin2hex(random_bytes(8)) . '.sav';
        $result = $adapter->export('Old catalog export', $target);
        unlink($target);

        self::assertSame(2, $result->caseCount);
        self::assertSame([['schema_version' => 4]], self::rows($pdo, 'SELECT schema_version FROM catalog_identity'));
    }

    public function testExportIsReadOnlyAndPreservesDestinationOnWriterFailure(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $engine = new FakeSpssEngine($this->fixture());
        $adapter = new SpssAdapter($pdo, $engine);
        $adapter->import('fixture.sav', 'read only');
        $pdo->exec("UPDATE variable SET variable_label = 'Authoritative label' WHERE source_ordinal = 1");
        $before = self::rows($pdo, 'SELECT total_changes() AS changes');
        $pdo->exec('PRAGMA query_only = ON');
        $target = sys_get_temp_dir() . '/oss-export-' . bin2hex(random_bytes(8)) . '.zsav';
        try {
            $result = $adapter->export('read only', $target, allowLoss: ['caller_accepted_loss']);
            self::assertSame(['caller_accepted_loss'], $result->allowLoss);
            self::assertSame($target, $result->targetPath);
            self::assertArrayNotHasKey('operationId', get_object_vars($result));
            self::assertSame('Authoritative label', $engine->lastWrite()['dataset']->variables()[0]->label);
            self::assertSame('zsav', $engine->lastWrite()['dataset']->technicalMetadata->sourceFormat);
            file_put_contents($target, 'existing destination');
            $engine->writeFailure = new \RuntimeException('writer failed');
            try {
                $adapter->export('read only', $target);
                self::fail('Writer failure was swallowed.');
            } catch (\RuntimeException $exception) {
                self::assertSame('writer failed', $exception->getMessage());
            }
            self::assertSame('existing destination', file_get_contents($target));
            self::assertFileDoesNotExist($engine->lastWrite()['targetPath']);
            self::assertSame($before, self::rows($pdo, 'SELECT total_changes() AS changes'));
        } finally {
            @unlink($target);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidExportMetadata(): iterable
    {
        yield 'storage' => ["storage_kind = 'invalid'"];
        yield 'unknown format' => ["print_format_family = 'invalid'"];
        yield 'empty format' => ["print_format_family = ''"];
        yield 'partial format' => ['print_format_width = NULL'];
        yield 'unknown measurement' => ["measurement_level = 'invalid'"];
    }

    #[DataProvider('invalidExportMetadata')]
    public function testExportRejectsInvalidNormativeMetadataWithoutTouchingDestinationOrDatabase(string $assignment): void
    {
        $pdo = new PDO('sqlite::memory:');
        $adapter = new SpssAdapter($pdo, new FakeSpssEngine($this->fixture()));
        $adapter->import('fixture.sav', 'invalid dictionary');
        $pdo->exec("UPDATE variable SET $assignment WHERE source_ordinal = 1");
        $before = self::rows($pdo, 'SELECT total_changes() AS changes');
        $pdo->exec('PRAGMA query_only = ON');
        $target = sys_get_temp_dir() . '/oss-' . bin2hex(random_bytes(8)) . '.sav';
        file_put_contents($target, 'keep destination');
        try {
            $adapter->export('invalid dictionary', $target, allowLoss: ['invalid_source_dataset']);
            self::fail('allowLoss bypassed invalid dictionary validation.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::InvalidSourceDataset, $exception->diagnosticCode);
            self::assertSame('keep destination', file_get_contents($target));
            self::assertSame($before, self::rows($pdo, 'SELECT total_changes() AS changes'));
        } finally {
            unlink($target);
        }
    }

    public function testExportDoesNotInitializeFreshOrPendingCatalogs(): void
    {
        foreach ([false, true] as $pending) {
            $pdo = new PDO('sqlite::memory:');
            if ($pending) {
                CatalogOwnership::ensure($pdo);
            }
            $before = self::rows($pdo, 'SELECT * FROM sqlite_master');
            $changes = self::rows($pdo, 'SELECT total_changes() AS changes');
            $pdo->exec('PRAGMA query_only = ON');
            try {
                (new SpssAdapter($pdo))->export('absent', 'unused.sav');
                self::fail('Export initialized a catalog.');
            } catch (UnsupportedOperation $exception) {
                self::assertSame(DiagnosticCode::CatalogMigrationRequired, $exception->diagnosticCode);
            }
            self::assertSame($before, self::rows($pdo, 'SELECT * FROM sqlite_master'));
            self::assertSame($changes, self::rows($pdo, 'SELECT total_changes() AS changes'));
        }
    }

    public function testReadOnlyFreshInitializationFailureDoesNotCreateCurrentIdentity(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA query_only = ON');
        $adapter = new SpssAdapter($pdo, new FakeSpssEngine($this->fixture()));

        try {
            $adapter->import('fixture.sav', 'Read-only setup');
            self::fail('Read-only fresh initialization unexpectedly succeeded.');
        } catch (\PDOException) {
            self::assertSame([], self::rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'catalog_identity'"));
        }
    }

    public function testFreshPendingInitializationFailureDoesNotClaimCurrentIdentity(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        CatalogOwnership::ensure($pdo);
        $pdo->exec('CREATE TABLE interrupted_setup (id INTEGER PRIMARY KEY)');
        $adapter = new SpssAdapter($pdo, new FakeSpssEngine($this->fixture()));

        try {
            $adapter->import('fixture.sav', 'Interrupted setup');
            self::fail('A non-empty pending namespace was initialized automatically.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::CatalogMigrationRequired, $exception->diagnosticCode);
        }

        self::assertSame([['schema_version' => 1]], self::rows($pdo, 'SELECT schema_version FROM catalog_identity'));
        self::assertSame([], self::rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'operation_catalog'"));
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
        self::assertSame([['schema_version' => 4]], self::rows($pdo, 'SELECT schema_version FROM catalog_identity'));
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
        // A complete dictionary must come from normative rows, not the legacy read model.
        foreach ([
            'multiple_response_set_members', 'multiple_response_sets',
            'variable_set_members', 'variable_sets', 'variable_attributes',
            'file_attributes', 'variable_roles', 'variable_display_metadata',
            'missing_rule_values', 'missing_rules', 'value_labels', 'documents',
            'dataset_metadata', 'dataset_weight_variables', 'variables',
        ] as $table) {
            $pdo->exec('DELETE FROM ' . $table);
        }
        $pdo->exec('PRAGMA query_only = ON');
        $target = sys_get_temp_dir() . '/oss-' . bin2hex(random_bytes(8)) . '.sav';
        $result = $adapter->export('Customer survey', $target);
        unlink($target);
        $written = $engine->lastWrite()['dataset'];

        self::assertSame([], $result->diagnostics);
        self::assertSame([], $result->allowLoss);
        self::assertArrayNotHasKey('operationId', get_object_vars($result));
        self::assertSame(
            [
                ['direction' => 'import', 'status' => 'succeeded', 'dataset_name' => 'Customer survey', 'target_path' => 'fixture.sav', 'allow_loss' => '[]', 'failure_code' => null],
            ],
            self::rows($pdo, 'SELECT direction, status, dataset_name, target_path, allow_loss, failure_code FROM operation_catalog ORDER BY rowid'),
        );
        self::assertSame(["{\"package\":\"fake-spss-engine\",\"version\":\"test\",\"active_version\":\"test\",\"claimed_version_range\":\"test-only\",\"ci_tested_versions\":[\"test\"],\"claimed_supported\":true}"], array_column(self::rows($pdo, 'SELECT engine_details FROM operation_catalog ORDER BY rowid'), 'engine_details'));
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

    public function testGuardedEngineKeepsPhysicalDescriptorPathOutOfDatabaseProvenance(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $physicalDescriptorPath = '/proc/self/fd/17';
        $innerEngine = new FakeSpssEngine($this->fixture('sav'));
        $engine = new GuardedImportSpssEngine($innerEngine, $physicalDescriptorPath, 'sav');
        $adapter = new SpssAdapter($pdo, $engine);
        $verifiedSourceSha256 = str_repeat('a', 64);

        $adapter->import(
            $engine->logicalPath(),
            'Guarded import',
            verifiedSourceSha256: $verifiedSourceSha256,
        );

        self::assertSame('source.sav', $engine->logicalPath());
        self::assertSame($physicalDescriptorPath, $innerEngine->lastReadPath());
        self::assertSame(
            [['source_hash' => $verifiedSourceSha256]],
            self::rows($pdo, 'SELECT source_hash FROM dataset WHERE dataset_name = "Guarded import"'),
        );
        $journal = self::rows(
            $pdo,
            'SELECT target_path, engine_details FROM operation_catalog WHERE direction = "import"',
        );
        self::assertSame('source.sav', $journal[0]['target_path']);
        self::assertStringNotContainsString($physicalDescriptorPath, $journal[0]['engine_details']);
        self::assertSame([], self::rows($pdo, 'SELECT source_item FROM fidelity_event_catalog'));
        self::assertSame([], self::rows($pdo, 'SELECT source_item FROM fidelity_event'));
    }

    public function testGuardedEngineSanitizesPhysicalDescriptorReadFailureBeforeJournaling(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $physicalDescriptorPath = '/proc/self/fd/17';
        $innerEngine = new FakeSpssEngine(
            $this->fixture('sav'),
            new RuntimeException('Failed to read ' . $physicalDescriptorPath),
        );
        $engine = new GuardedImportSpssEngine($innerEngine, $physicalDescriptorPath, 'sav');
        $adapter = new SpssAdapter($pdo, $engine);

        try {
            $adapter->import(
                $engine->logicalPath(),
                'Rejected guarded import',
                verifiedSourceSha256: str_repeat('a', 64),
            );
            self::fail('A guarded inner read failure was not reported.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::InvalidSourceDataset, $exception->diagnosticCode);
            self::assertStringContainsString('source.sav', $exception->getMessage());
            self::assertStringNotContainsString($physicalDescriptorPath, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertSame($physicalDescriptorPath, $innerEngine->lastReadPath());
        $persisted = json_encode([
            'operation_catalog' => self::rows($pdo, 'SELECT * FROM operation_catalog'),
            'fidelity_event_catalog' => self::rows($pdo, 'SELECT * FROM fidelity_event_catalog'),
            'operation' => self::rows($pdo, 'SELECT * FROM operation'),
            'fidelity_event' => self::rows($pdo, 'SELECT * FROM fidelity_event'),
            'dataset' => self::rows($pdo, 'SELECT * FROM dataset'),
        ], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('source.sav', $persisted);
        self::assertStringNotContainsString($physicalDescriptorPath, $persisted);
    }

    public function testGuardedEngineRejectsReturnedTechnicalFormatMismatchWithoutJournalLeak(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $physicalDescriptorPath = '/proc/self/fd/17';
        $innerEngine = new FakeSpssEngine($this->fixture('zsav'));
        $engine = new GuardedImportSpssEngine($innerEngine, $physicalDescriptorPath, 'sav');
        $adapter = new SpssAdapter($pdo, $engine);

        try {
            $adapter->import(
                $engine->logicalPath(),
                'Rejected guarded format mismatch',
                verifiedSourceSha256: str_repeat('a', 64),
            );
            self::fail('A guarded dataset with mismatched technical source format was accepted.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::InvalidSourceDataset, $exception->diagnosticCode);
            self::assertSame(
                'The guarded SPSS source could not be read for logical path source.sav.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString($physicalDescriptorPath, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertSame($physicalDescriptorPath, $innerEngine->lastReadPath());
        self::assertSame(
            [[
                'target_path' => 'source.sav',
                'source_format' => 'sav',
                'status' => 'failed',
                'failure_code' => 'invalid_source_dataset',
            ]],
            self::rows(
                $pdo,
                'SELECT target_path, source_format, status, failure_code FROM operation_catalog',
            ),
        );
        $persisted = json_encode([
            'operation_catalog' => self::rows($pdo, 'SELECT * FROM operation_catalog'),
            'fidelity_event_catalog' => self::rows($pdo, 'SELECT * FROM fidelity_event_catalog'),
            'operation' => self::rows($pdo, 'SELECT * FROM operation'),
            'fidelity_event' => self::rows($pdo, 'SELECT * FROM fidelity_event'),
            'dataset' => self::rows($pdo, 'SELECT * FROM dataset'),
        ], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('source.sav', $persisted);
        self::assertStringNotContainsString($physicalDescriptorPath, $persisted);
        self::assertSame([], self::rows($pdo, 'SELECT * FROM dataset'));
    }

    public function testGuardedEngineRejectsDescriptorBearingNestedIdentityWithoutJournaling(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $physicalDescriptorPath = '/proc/self/fd/17';
        $maliciousIdentities = [
            ['package' => 'malicious', 'nested' => ['detail' => $physicalDescriptorPath]],
            ['package' => 'malicious', 'nested' => ['detail' => 'opened /dev/fd/22']],
            ['package' => 'malicious', 'nested' => ['descriptor-/proc/123/fd/8' => true]],
        ];

        foreach ($maliciousIdentities as $maliciousIdentity) {
            $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $innerEngine = new FakeSpssEngine(
                $this->fixture(),
                identityOverride: $maliciousIdentity,
            );
            $engine = new GuardedImportSpssEngine($innerEngine, $physicalDescriptorPath, 'sav');
            $adapter = new SpssAdapter($pdo, $engine);

            try {
                $adapter->import(
                    $engine->logicalPath(),
                    'Rejected guarded identity',
                    verifiedSourceSha256: str_repeat('a', 64),
                );
                self::fail('A descriptor-bearing engine identity was accepted.');
            } catch (UnsupportedOperation $exception) {
                self::assertSame(DiagnosticCode::InvalidSourceDataset, $exception->diagnosticCode);
                self::assertSame(
                    'The guarded SPSS engine identity is not safe for journaling.',
                    $exception->getMessage(),
                );
                self::assertDoesNotMatchRegularExpression(
                    '~(?:/proc/(?:self|thread-self|[0-9]+)/fd/[0-9]+|/dev/fd/[0-9]+)~',
                    $exception->getMessage(),
                );
                self::assertNull($exception->getPrevious());
            }

            self::assertSame(
                [],
                self::rows(
                    $pdo,
                    "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('operation_catalog', 'fidelity_event_catalog')",
                ),
            );
            self::assertSame([], self::rows($pdo, 'SELECT * FROM operation'));
            self::assertSame([], self::rows($pdo, 'SELECT * FROM fidelity_event'));
            self::assertSame([], self::rows($pdo, 'SELECT * FROM dataset'));
        }
    }

    public function testGuardedEngineSupportsZsavLogicalPathWithDevFdSource(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $physicalDescriptorPath = '/dev/fd/23';
        $innerEngine = new FakeSpssEngine($this->fixture('zsav'));
        $engine = new GuardedImportSpssEngine($innerEngine, $physicalDescriptorPath, 'zsav');
        $adapter = new SpssAdapter($pdo, $engine);
        $verifiedSourceSha256 = str_repeat('b', 64);

        $adapter->import(
            $engine->logicalPath(),
            'Guarded ZSAV import',
            verifiedSourceSha256: $verifiedSourceSha256,
        );

        self::assertSame('source.zsav', $engine->logicalPath());
        self::assertSame($physicalDescriptorPath, $innerEngine->lastReadPath());
        self::assertSame(
            [['target_path' => 'source.zsav', 'source_format' => 'zsav']],
            self::rows(
                $pdo,
                'SELECT target_path, source_format FROM operation_catalog WHERE direction = "import"',
            ),
        );
        self::assertSame(
            [['source_format' => 'zsav', 'source_hash' => $verifiedSourceSha256]],
            self::rows(
                $pdo,
                'SELECT source_format, source_hash FROM dataset WHERE dataset_name = "Guarded ZSAV import"',
            ),
        );
    }

    public function testGuardedEngineRejectsNonJsonSafeIdentityWithoutJournaling(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $physicalDescriptorPath = '/proc/self/fd/17';
        $unsafeIdentities = [
            ['package' => 'malicious', 'detail' => INF],
            ['package' => 'malicious', 'detail' => NAN],
            ['package' => 'malicious', 'detail' => "\xC3\x28"],
            ['package' => 'malicious', "invalid-key-\xC3\x28" => true],
        ];

        foreach ($unsafeIdentities as $unsafeIdentity) {
            $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $innerEngine = new FakeSpssEngine(
                $this->fixture(),
                identityOverride: $unsafeIdentity,
            );
            $engine = new GuardedImportSpssEngine($innerEngine, $physicalDescriptorPath, 'sav');
            $adapter = new SpssAdapter($pdo, $engine);

            try {
                $adapter->import(
                    $engine->logicalPath(),
                    'Rejected non-JSON-safe identity',
                    verifiedSourceSha256: str_repeat('a', 64),
                );
                self::fail('A non-JSON-safe engine identity was accepted.');
            } catch (UnsupportedOperation $exception) {
                self::assertSame(DiagnosticCode::InvalidSourceDataset, $exception->diagnosticCode);
                self::assertSame(
                    'The guarded SPSS engine identity is not safe for journaling.',
                    $exception->getMessage(),
                );
                self::assertNull($exception->getPrevious());
            }

            self::assertSame(
                [],
                self::rows(
                    $pdo,
                    "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('operation_catalog', 'fidelity_event_catalog')",
                ),
            );
            self::assertSame([], self::rows($pdo, 'SELECT * FROM operation'));
            self::assertSame([], self::rows($pdo, 'SELECT * FROM fidelity_event'));
            self::assertSame([], self::rows($pdo, 'SELECT * FROM dataset'));
        }
    }

    public function testImportRejectsEphemeralDescriptorPathsBeforeDatabaseMutation(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        foreach (['/proc/self/fd/7', '/proc/thread-self/fd/7', '/proc/123/fd/7', '/dev/fd/7'] as $sourcePath) {
            $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $adapter = new SpssAdapter($pdo, new FakeSpssEngine($this->fixture()));

            try {
                $adapter->import(
                    $sourcePath,
                    'Rejected descriptor import',
                    verifiedSourceSha256: str_repeat('a', 64),
                );
                self::fail('An ephemeral descriptor path was accepted.');
            } catch (UnsupportedOperation $exception) {
                self::assertSame(DiagnosticCode::InvalidSourceDataset, $exception->diagnosticCode);
            }

            self::assertSame(
                [],
                self::rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table'"),
            );
        }
    }

    public function testImportWithoutExplicitHashRetainsReadablePathHashing(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $sourcePath = sys_get_temp_dir() . '/openstatspec-source-hash-' . uniqid('', true) . '.sav';
        try {
            file_put_contents($sourcePath, 'source bytes');
            $expectedSourceSha256 = hash_file('sha256', $sourcePath);
            $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $adapter = new SpssAdapter($pdo, new FakeSpssEngine($this->fixture()));

            $adapter->import($sourcePath, 'Readable source');

            self::assertSame(
                [['source_hash' => $expectedSourceSha256]],
                self::rows($pdo, 'SELECT source_hash FROM dataset WHERE dataset_name = "Readable source"'),
            );
        } finally {
            @unlink($sourcePath);
        }
    }

    public function testImportRejectsInvalidVerifiedSha256BeforeDatabaseMutation(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        foreach (['abc', str_repeat('A', 64)] as $invalidSourceSha256) {
            $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $adapter = new SpssAdapter($pdo, new FakeSpssEngine($this->fixture()));

            try {
                $adapter->import(
                    'source.sav',
                    'Rejected import',
                    verifiedSourceSha256: $invalidSourceSha256,
                );
                self::fail('An invalid verified source SHA-256 was accepted.');
            } catch (UnsupportedOperation $exception) {
                self::assertSame(DiagnosticCode::InvalidSourceDataset, $exception->diagnosticCode);
            }

            self::assertSame(
                [],
                self::rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table'"),
            );
        }
    }

    /** @return iterable<string, array{list<array<string, mixed>>, ?Measure}> */
    public static function exportTransformations(): iterable
    {
        yield 'format' => [[['op' => 'set_format', 'variable' => 'Respondent_ID', 'family' => 'F', 'width' => 10, 'decimals' => 2]], null];
        foreach (['nominal' => Measure::NOMINAL, 'ordinal' => Measure::ORDINAL, 'scale' => Measure::SCALE] as $level => $measure) {
            yield $level => [[['op' => 'set_measurement_level', 'variable' => 'Respondent_ID', 'level' => $level]], $measure];
        }
        yield 'assignment target' => [[['op' => 'assign', 'target' => 'Created', 'target_mode' => 'create', 'value' => ['kind' => 'variable', 'variable' => 'Respondent_ID']]], null];
        yield 'recode target' => [[['op' => 'recode', 'source' => 'Respondent_ID', 'target' => 'Created', 'target_mode' => 'create', 'rules' => [['match' => ['kind' => 'values', 'values' => [['type' => 'binary64', 'bits' => '401c000000000000']]], 'result' => ['kind' => 'copy']]], 'unmatched' => ['kind' => 'copy']]], null];
    }

    /** @param list<array<string, mixed>> $operations */
    #[DataProvider('exportTransformations')]
    public function testRealExportAfterTransformation(array $operations, ?Measure $measure): void
    {
        $source = sys_get_temp_dir() . '/oss-transform-' . bin2hex(random_bytes(8)) . '.sav';
        $target = $source . '.zsav';
        $engine = new PhpSpssEngine();
        $pdo = new PDO('sqlite::memory:');
        $adapter = new SpssAdapter($pdo, $engine);
        try {
            $engine->write($source, $this->engineFixture());
            $adapter->import($source, 'transformed');
            // Imported IDs currently have random UUID variant bits; use a valid apply binding.
            $pdo->exec('PRAGMA foreign_keys = OFF');
            foreach (['dataset', 'variable', 'value_label_set', 'dataset_weight_variable', 'document', 'fidelity_event'] as $table) {
                $pdo->exec("UPDATE $table SET dataset_id = '018f47f2-8b6a-4c3d-8e1f-123456789abc' WHERE dataset_id IS NOT NULL");
            }
            $pdo->exec('PRAGMA foreign_keys = ON');
            $dataset = self::rows($pdo, 'SELECT dataset_id, source_hash FROM dataset')[0];
            $plan = (new PlanCodec())->fromArray(['contract' => 'openstatspec-transformation-plan-v0.2', 'input_alias' => 'parent', 'operations' => $operations]);
            (new InPlaceTransformationExecutor(new Connection($pdo)))->execute(new InPlaceApplyRequest($plan, 'parent', $dataset['dataset_id'], $dataset['source_hash'], 'export regression'));
            $before = self::rows($pdo, 'SELECT total_changes() AS changes');
            $pdo->exec('PRAGMA query_only = ON');
            foreach (['sav', 'zsav'] as $format) {
                $target = $source . '.' . $format;
                $adapter->export('transformed', $target);
                $read = $engine->read($target);
                $created = isset($operations[0]['target']);
                self::assertSame($created ? [[7.0, 'blue', 7.0], [8.0, 'green', 8.0]] : $this->engineFixture()->rows(), $read->rows());
                self::assertSame($measure ?? Measure::SCALE, $read->variables()[0]->measure);
                $expected = new VariableFormat(5, $operations[0]['op'] === 'set_format' ? 10 : 8, $operations[0]['op'] === 'set_format' ? 2 : 0);
                self::assertEquals($expected, $read->variables()[0]->printFormat);
                self::assertEquals($expected, $read->variables()[0]->writeFormat);
                if ($created) {
                    self::assertEquals(new VariableFormat(5, 8, 2), $read->variables()[2]->printFormat);
                    self::assertEquals(new VariableFormat(5, 8, 2), $read->variables()[2]->writeFormat);
                }
                self::assertSame($before, self::rows($pdo, 'SELECT total_changes() AS changes'));
                unlink($target);
            }
        } finally {
            @unlink($source);
            @unlink($target);
        }
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

    public function testExportReadsCanonicalCatalogInsteadOfLegacyMetadata(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:');
        $engine = new FakeSpssEngine($this->fixture());
        $adapter = new SpssAdapter($pdo, $engine);
        $adapter->import('fixture.sav', 'canonical fixture');
        $pdo->exec("UPDATE variable SET variable_label = 'Canonical label' WHERE dataset_id = (SELECT dataset_id FROM dataset WHERE dataset_name = 'canonical fixture') AND source_ordinal = 1");
        $pdo->exec("UPDATE variables SET label = 'Legacy-only label' WHERE dataset_name = 'canonical fixture' AND ordinal = 1");
        $target = sys_get_temp_dir() . '/oss-' . bin2hex(random_bytes(8)) . '.sav';
        $adapter->export('canonical fixture', $target);
        unlink($target);

        self::assertSame('Canonical label', $engine->lastWrite()['dataset']->variables()[0]->label);
        $legacyLabel = $pdo->query("SELECT label FROM variables WHERE dataset_name = 'canonical fixture' AND ordinal = 1");
        self::assertInstanceOf(\PDOStatement::class, $legacyLabel);
        self::assertSame('Legacy-only label', $legacyLabel->fetchColumn());
    }

    public function testExportRestoresCataloguedTechnicalMetadataWhileTargetDeterminesContainer(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $engine = new FakeSpssEngine($this->fixture());
        $adapter = new SpssAdapter(new PDO('sqlite::memory:'), $engine);
        $adapter->import('fixture.zsav', 'technical fixture');
        $target = sys_get_temp_dir() . '/oss-' . bin2hex(random_bytes(8)) . '.sav';
        $adapter->export('technical fixture', $target);
        unlink($target);

        $technical = $engine->lastWrite()['dataset']->technicalMetadata;
        self::assertSame('sav', $technical->sourceFormat);
        self::assertSame('$FL2', $technical->recordType);
        self::assertSame(1, $technical->compression);
        self::assertSame('OpenStatSpec 0.1', $technical->sourceVersion);
        self::assertSame("P\u{00E4}ritolu: k\u{00FC}sitlus", $technical->provenance);
        self::assertSame('UTF-8', $technical->encoding);
        self::assertSame("OpenStatSpec t\u{00F6}\u{00F6}riist", $technical->productName);
    }

    public function testMarkerV3RejectsLegacyOnlyCatalog(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        (new SqliteWideTableImporter($pdo))->import(SpssSourceNormalizer::normalize($this->fixture()), 'Legacy v3 marker');
        $pdo->exec('CREATE TABLE openstatspec_schema_migration (version INTEGER NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL)');
        $insert = $pdo->prepare('INSERT INTO openstatspec_schema_migration (version, applied_at) VALUES (?, ?)');
        foreach ([1, 2, 3] as $version) {
            $insert->execute([$version, '2026-07-28 00:00:00']);
        }

        try {
            CatalogOwnership::ensure($pdo);
            self::fail('A v3 marker claimed a legacy-only catalogue.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::CatalogNamespaceCollision, $exception->diagnosticCode);
        }

        self::assertSame([], self::rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'catalog_identity'"));
    }

    public function testMigrateCatalogBackfillsLegacyDatasetsAndIsIdempotent(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:');
        $engine = new FakeSpssEngine($this->fixture());
        (new SqliteWideTableImporter($pdo))->import(SpssSourceNormalizer::normalize($this->fixture()), 'Legacy survey');

        self::assertSame([], self::rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'dataset'"));

        $adapter = new SpssAdapter($pdo, $engine);
        try {
            $adapter->export('Legacy survey', 'legacy-before-migration.sav');
            self::fail('Pre-identity legacy catalogue was used without explicit migration.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::CatalogMigrationRequired, $exception->diagnosticCode);
        }
        self::assertSame([], self::rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('catalog_identity', 'operation_catalog')"));

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
            [['version' => 1], ['version' => 2], ['version' => 3], ['version' => 4]],
            self::rows($pdo, 'SELECT version FROM openstatspec_schema_migration ORDER BY version'),
        );
        self::assertSame(
            [['variable_count' => 2]],
            self::rows($pdo, 'SELECT COUNT(*) AS variable_count FROM variable'),
        );

        $target = sys_get_temp_dir() . '/oss-' . bin2hex(random_bytes(8)) . '.sav';
        $export = $adapter->export('Legacy survey', $target);
        unlink($target);
        self::assertSame(2, $export->caseCount);
        self::assertSame([[7.0, 'blue'], [8.0, 'green']], $engine->lastWrite()['dataset']->rows());
    }

    public function testV3MigrationRestoresSetOrdinalConstraints(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $catalog = new \OpenStatSpec\Sql\NormativeCatalog($pdo);
        $catalog->createTables();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('DROP TABLE variable_set');
        $pdo->exec('DROP TABLE multiple_response_set');
        $pdo->exec('CREATE TABLE variable_set (variable_set_id VARCHAR(36) PRIMARY KEY, dataset_id VARCHAR(36) NOT NULL, set_name VARCHAR(255) NOT NULL)');
        $pdo->exec('CREATE TABLE multiple_response_set (multiple_response_set_id VARCHAR(36) PRIMARY KEY, dataset_id VARCHAR(36) NOT NULL, set_name VARCHAR(255) NOT NULL, set_label TEXT NULL, set_kind VARCHAR(4) NOT NULL, counted_numeric_value DOUBLE NULL, category_label_behavior TEXT NULL)');
        $pdo->exec("INSERT INTO dataset (dataset_id, spec_version, source_format, physical_table_name, dataset_name, source_case_count, imported_at) VALUES ('dataset-v3', '1.0', 'sav', 'data_v3', 'dataset-v3', 0, '2026-07-28 00:00:00')");
        $pdo->exec("INSERT INTO variable_set VALUES ('vs-b', 'dataset-v3', 'Second'), ('vs-a', 'dataset-v3', 'First')");
        $pdo->exec("INSERT INTO multiple_response_set VALUES ('mr-b', 'dataset-v3', 'Second MR', NULL, 'MC', NULL, NULL), ('mr-a', 'dataset-v3', 'First MR', NULL, 'MC', NULL, NULL)");
        $pdo->exec('CREATE TABLE IF NOT EXISTS variable_sets (dataset_name TEXT NOT NULL, set_ordinal INTEGER NOT NULL, name TEXT NOT NULL, PRIMARY KEY (dataset_name, set_ordinal), UNIQUE (dataset_name, name))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS multiple_response_sets (dataset_name TEXT NOT NULL, set_ordinal INTEGER NOT NULL, name TEXT NOT NULL, set_type TEXT NOT NULL, label TEXT NULL, counted_value_kind TEXT NULL, counted_numeric_value REAL NULL, counted_text_value TEXT NULL, category_labels TEXT NOT NULL, label_source TEXT NOT NULL, PRIMARY KEY (dataset_name, set_ordinal), UNIQUE (dataset_name, name))');
        $pdo->exec("INSERT INTO variable_sets (dataset_name, set_ordinal, name) VALUES ('dataset-v3', 1, 'Second'), ('dataset-v3', 2, 'First')");
        $pdo->exec("INSERT INTO multiple_response_sets (dataset_name, set_ordinal, name, set_type, category_labels, label_source) VALUES ('dataset-v3', 1, 'Second MR', 'category', 'variable_labels', 'set_label'), ('dataset-v3', 2, 'First MR', 'category', 'variable_labels', 'set_label')");
        $pdo->exec('DELETE FROM openstatspec_schema_migration WHERE version = 3');

        $catalog->createTables();

        self::assertSame([[1], [2]], array_map('array_values', self::rows($pdo, 'SELECT source_ordinal FROM variable_set ORDER BY source_ordinal')));
        self::assertSame([[1], [2]], array_map('array_values', self::rows($pdo, 'SELECT source_ordinal FROM multiple_response_set ORDER BY source_ordinal')));
        $variableColumns = self::rows($pdo, 'PRAGMA table_info(variable_set)');
        $mrColumns = self::rows($pdo, 'PRAGMA table_info(multiple_response_set)');
        self::assertSame(1, (int) array_values(array_filter($variableColumns, static fn(array $column): bool => $column['name'] === 'source_ordinal'))[0]['notnull']);
        self::assertSame(1, (int) array_values(array_filter($mrColumns, static fn(array $column): bool => $column['name'] === 'source_ordinal'))[0]['notnull']);
        self::assertSame([], self::rows($pdo, 'PRAGMA foreign_key_check'));
        self::assertSame([['set_name' => 'Second MR'], ['set_name' => 'First MR']], self::rows($pdo, 'SELECT set_name FROM multiple_response_set ORDER BY source_ordinal'));
        $schema = self::rows($pdo, "SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name IN ('variable_set', 'multiple_response_set') ORDER BY name");
        $catalog->createTables();
        self::assertSame($schema, self::rows($pdo, "SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name IN ('variable_set', 'multiple_response_set') ORDER BY name"));
        self::assertSame([['version' => 3]], self::rows($pdo, 'SELECT version FROM openstatspec_schema_migration WHERE version = 3'));

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO variable_set (variable_set_id, dataset_id, source_ordinal, set_name) VALUES ('vs-duplicate', 'dataset-v3', 1, 'Duplicate')");
    }

    /** @return iterable<string, array{bool}> */
    public static function importEntryPoints(): iterable
    {
        yield 'adapter' => [false];
        yield 'direct normalized source' => [true];
    }

    /** @return iterable<string, array{bool, string, float}> */
    public static function binary64ImportCases(): iterable
    {
        foreach (self::importEntryPoints() as $entry => [$direct]) {
            yield $entry . ' default precision' => [$direct, '-1', 1.2345678901234567];
            yield $entry . ' tiny float' => [$direct, '-1', -7.425696547609993e-37];
            yield $entry . ' serialize_precision=3' => [$direct, '3', 1.234];
        }
    }

    #[DataProvider('binary64ImportCases')]
    public function testImportPreservesBinary64CasesAndNumericMetadata(bool $direct, string $precision, float $value): void
    {
        $pdo = new PDO('sqlite::memory:');
        $counted = 9007199254740991;
        $source = new Dataset(
            $this->fixture()->dictionary,
            [[$value, 'blue'], [null, ''], [7, 'green']],
            new FileMetadata(multipleResponseSets: [
                new MultipleResponseSet('$Selected', MultipleResponseSetType::DICHOTOMY, ['Respondent ID'], countedValue: $counted),
            ]),
            new FileTechnicalMetadata(sourceFormat: 'sav', compressionBias: $value),
        );
        $adapter = new SpssAdapter($pdo, new FakeSpssEngine($source));
        $originalPrecision = ini_get('serialize_precision');
        try {
            ini_set('serialize_precision', $precision);
            if ($direct) {
                $adapter->migrateCatalog();
                $normalized = SpssSourceNormalizer::normalize($source);
                $normalized['multipleResponseSets'][0]['countedValue'] = (float) $counted;
                (new SqliteWideTableImporter($pdo))->import($normalized, 'precision', 'fixture.sav');
            } else {
                $adapter->import('fixture.sav', 'precision');
            }
        } finally {
            ini_set('serialize_precision', $originalPrecision);
        }

        $rows = self::rows($pdo, 'SELECT * FROM dataset_precision ORDER BY __case_ordinal');
        self::assertSame([1, 2, 3], array_column($rows, '__case_ordinal'));
        self::assertSame(['blue', '', 'green'], array_column($rows, 'favourite_colour'));
        self::assertNull($rows[1]['respondent_id']);
        self::assertSame(7.0, $rows[2]['respondent_id']);
        $actual = ['case' => $rows[0]['respondent_id']];
        foreach ([
            'compression bias' => 'SELECT compression_bias FROM file_technical_metadata',
            'legacy counted value' => 'SELECT counted_numeric_value FROM multiple_response_sets',
            'normative counted value' => 'SELECT counted_numeric_value FROM multiple_response_set',
        ] as $field => $sql) {
            $actual[$field] = array_values(self::rows($pdo, $sql)[0])[0];
        }
        self::assertSame(
            array_map(static fn($number): string => bin2hex(pack('E', $number)), [
                'case' => $value, 'compression bias' => $value,
                'legacy counted value' => $counted, 'normative counted value' => $counted,
            ]),
            array_map(static fn($number): string => bin2hex(pack('E', $number)), $actual),
        );
        self::assertFalse($pdo->inTransaction());
    }

    /** @return iterable<string, array{int, bool}> */
    public static function nonExceptionImportModes(): iterable
    {
        foreach (['silent' => PDO::ERRMODE_SILENT, 'warning' => PDO::ERRMODE_WARNING] as $name => $mode) {
            foreach (self::importEntryPoints() as $entry => [$direct]) {
                yield $name . ' ' . $entry => [$mode, $direct];
            }
        }
    }

    #[DataProvider('nonExceptionImportModes')]
    public function testImportCannotPublishPartialSuccessAndPreservesCallerErrorMode(int $mode, bool $direct): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => $mode]);
        $source = $this->fixture();
        $adapter = new SpssAdapter($pdo, new FakeSpssEngine($source));
        $adapter->migrateCatalog();
        $import = static function (string $name) use ($pdo, $source, $adapter, $direct): void {
            if ($direct) {
                (new SqliteWideTableImporter($pdo))->import(SpssSourceNormalizer::normalize($source), $name, 'fixture.sav');
            } else {
                $adapter->import('fixture.sav', $name);
            }
        };
        $import('prior');
        self::assertSame($mode, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertFalse($pdo->inTransaction());
        self::assertCount(2, self::rows($pdo, 'SELECT * FROM dataset_prior'));
        $pdo->exec("ALTER TABLE documents ADD COLUMN import_check INTEGER CONSTRAINT reject_document CHECK (dataset_name <> 'attempt' OR ordinal <> 2)");
        $before = [];
        foreach (self::rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT IN ('operation_catalog', 'operation', 'fidelity_event_catalog', 'fidelity_event')") as $table) {
            $before[$table['name']] = self::rows($pdo, 'SELECT * FROM "' . $table['name'] . '"');
        }

        $failure = null;
        try {
            $import('attempt');
        } catch (\PDOException $exception) {
            $failure = $exception;
        }
        self::assertSame($mode, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertFalse($pdo->inTransaction());
        self::assertNotNull($failure, 'A rejected document was silently committed as a successful import.');
        self::assertStringContainsString('reject_document', $failure->getMessage());
        self::assertSame([], self::rows($pdo, "SELECT name FROM sqlite_master WHERE name = 'dataset_attempt'"));
        foreach ($before as $table => $rows) {
            self::assertSame($rows, self::rows($pdo, 'SELECT * FROM "' . $table . '"'), $table);
        }
        if (!$direct) {
            self::assertSame([['status' => 'failed', 'dataset_name' => null]], self::rows($pdo, "SELECT status, dataset_name FROM operation_catalog WHERE status <> 'succeeded'"));
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function finalizationFailures(): iterable
    {
        yield 'hook throws' => [false];
        yield 'normative success update fails' => [true];
    }

    #[DataProvider('finalizationFailures')]
    public function testImportFinalizationFailureRollsBackOnlyThisAttempt(bool $journalFailure): void
    {
        $pdo = new PDO('sqlite::memory:');
        $engine = new FakeSpssEngine($this->fixture());
        $prior = (new SpssAdapter($pdo, $engine))->import('prior.sav', 'prior');
        $before = [];
        foreach (self::rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT IN ('operation_catalog', 'operation', 'fidelity_event_catalog', 'fidelity_event')") as $table) {
            $before[$table['name']] = self::rows($pdo, 'SELECT * FROM "' . $table['name'] . '"');
        }
        $inTransaction = false;
        $injected = new RuntimeException('Injected finalization failure.');
        $adapter = new SpssAdapter($pdo, $engine, beforeImportFinalization: static function () use ($pdo, $prior, $journalFailure, $injected, &$inTransaction): void {
            $inTransaction = $pdo->inTransaction();
            if (!$journalFailure) {
                throw $injected;
            }
            $pdo->exec("ALTER TABLE operation ADD COLUMN import_check INTEGER CONSTRAINT reject_success CHECK (status <> 'succeeded' OR operation_id = '{$prior->operationId}')");
        });
        try {
            $adapter->import('attempt.sav', 'attempt');
            self::fail('Finalization failure was swallowed.');
        } catch (RuntimeException $exception) {
            if ($journalFailure) {
                self::assertInstanceOf(\PDOException::class, $exception);
                self::assertStringContainsString('reject_success', $exception->getMessage());
            } else {
                self::assertSame($injected, $exception);
            }
        }
        self::assertFalse($pdo->inTransaction());
        self::assertSame([], self::rows($pdo, "SELECT name FROM sqlite_master WHERE name = 'dataset_attempt'"));
        foreach ($before as $table => $rows) {
            self::assertSame($rows, self::rows($pdo, 'SELECT * FROM "' . $table . '"'), $table);
        }
        self::assertTrue($inTransaction, 'Finalization must share the dataset transaction.');
        self::assertSame([
            ['target_path' => 'attempt.sav', 'status' => 'failed', 'dataset_name' => null, 'normative_status' => 'failed'],
            ['target_path' => 'prior.sav', 'status' => 'succeeded', 'dataset_name' => 'prior', 'normative_status' => 'succeeded'],
        ], self::rows($pdo, 'SELECT target_path, legacy.status, dataset_name, normative.status AS normative_status FROM operation_catalog legacy JOIN operation normative USING (operation_id) ORDER BY target_path'));
        self::assertSame([['dataset_name' => null, 'code' => 'operation_failed']], self::rows($pdo, 'SELECT dataset_name, code FROM fidelity_event_catalog'));
        self::assertSame([['dataset_id' => null, 'event_code' => 'operation_failed']], self::rows($pdo, 'SELECT dataset_id, event_code FROM fidelity_event'));
    }

    #[DataProvider('importEntryPoints')]
    public function testImportRejectsCallerOwnedTransactionWithoutMutation(bool $direct): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $source = $this->fixture();
        $adapter = new SpssAdapter($pdo, new FakeSpssEngine($source));
        $adapter->import('prior.sav', 'prior');
        $pdo->beginTransaction();
        $pdo->exec("UPDATE dataset_prior SET favourite_colour = 'pending' WHERE __case_ordinal = 1");
        $before = self::rows($pdo, 'SELECT * FROM sqlite_master');
        $changes = self::rows($pdo, 'SELECT total_changes() AS changes');
        $failure = null;
        try {
            if ($direct) {
                (new SqliteWideTableImporter($pdo))->import(SpssSourceNormalizer::normalize($source), 'attempt', 'fixture.sav');
            } else {
                $adapter->import('fixture.sav', 'attempt');
            }
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        self::assertTrue($pdo->inTransaction());
        self::assertSame(PDO::ERRMODE_SILENT, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame([['favourite_colour' => 'pending']], self::rows($pdo, 'SELECT favourite_colour FROM dataset_prior WHERE __case_ordinal = 1'));
        self::assertSame($before, self::rows($pdo, 'SELECT * FROM sqlite_master'));
        self::assertSame($changes, self::rows($pdo, 'SELECT total_changes() AS changes'));
        $pdo->rollBack();
        self::assertSame([['favourite_colour' => 'blue']], self::rows($pdo, 'SELECT favourite_colour FROM dataset_prior WHERE __case_ordinal = 1'));
        self::assertInstanceOf(UnsupportedOperation::class, $failure);
        self::assertSame(DiagnosticCode::UnsupportedOperation, $failure->diagnosticCode);
    }

    /** @return iterable<string, array{class-string}> */
    public static function groupedExporters(): iterable
    {
        foreach ([CanonicalWideTableExporter::class, SqliteWideTableExporter::class, MySqlWideTableExporter::class, PostgreSqlWideTableExporter::class, SpssAdapter::class] as $class) {
            yield substr($class, strrpos($class, '\\') + 1) => [$class];
        }
    }

    /** @param class-string<CanonicalWideTableExporter|SqliteWideTableExporter|MySqlWideTableExporter|PostgreSqlWideTableExporter|SpssAdapter> $class */
    #[DataProvider('groupedExporters')]
    public function testGroupedExportExecutionCountDoesNotGrow(string $class): void
    {
        $counts = [];
        $overhead = 0;
        foreach ([[2, 0, false], [20, 0, false], [20, 10, false], [2, 0, true]] as [$variables, $sets, $empty]) {
            $pdo = new ExportCountingPdo('sqlite::memory:');
            $source = $this->groupedExportFixture($variables, $sets, $empty);
            $engine = new FakeSpssEngine($source);
            $adapter = new SpssAdapter($pdo, $engine);
            $adapter->import('fixture.sav', 'A');
            $exporter = $class === SpssAdapter::class ? $adapter : new $class($pdo);
            $pdo->exec('PRAGMA query_only = ON');
            if ($class === SpssAdapter::class) {
                $pdo->executions = [];
                CatalogOwnership::assertReadyForUseReadOnly($pdo);
                $overhead = count($pdo->captured());
            }
            $pdo->executions = [];
            $pdo->prepares = 0;
            $export = $this->publicExport($exporter, $engine, 'A');
            $counts[] = count($pdo->captured()) - $overhead;
            self::assertSame($source->rowCount(), $export['caseCount']);
            self::assertSame([], $export['diagnostics']);
            self::assertSame($source->rows(), $export['dataset']->rows());
            foreach ($pdo->captured() as $execution) {
                self::assertMatchesRegularExpression('/^SELECT\b/i', $execution['sql']);
            }
        }
        $limit = in_array($class, [CanonicalWideTableExporter::class, SpssAdapter::class], true) ? 14 : 18;
        self::assertLessThanOrEqual($limit, max($counts), 'Executed SQL for (V,S,R)=(2,0,0),(20,0,0),(20,10,10),empty: ' . json_encode($counts));
        self::assertSame($counts[0], $counts[1], 'Adding variables must not add executions.');
    }

    /** @param class-string<CanonicalWideTableExporter|SqliteWideTableExporter|MySqlWideTableExporter|PostgreSqlWideTableExporter|SpssAdapter> $class */
    #[DataProvider('groupedExporters')]
    public function testGroupedExportPreservesOrderedMetadataIsolationAndFreshness(string $class): void
    {
        $pdo = new ExportCountingPdo('sqlite::memory:');
        $source = $this->groupedExportFixture(4, 3);
        $engine = new FakeSpssEngine($source);
        $adapter = new SpssAdapter($pdo, $engine);
        $adapter->import('fixture.sav', 'A');
        (new SpssAdapter($pdo, new FakeSpssEngine($this->groupedExportFixture(4, 3, changed: true))))->import('fixture.sav', 'B');
        (new SpssAdapter($pdo, new FakeSpssEngine($this->groupedExportFixture(2, 0, true))))->import('fixture.sav', 'C');
        // Legacy format-zero/absent rules deliberately ignore their value rows.
        $pdo->exec("DELETE FROM missing_rules WHERE dataset_name = 'C' AND variable_ordinal = 2");
        $pdo->exec("INSERT INTO missing_rule_values (dataset_name, variable_ordinal, ordinal, value_kind) VALUES ('C', 1, 1, 'text'), ('C', 2, 1, 'text')");
        // Valid shared label set: both owners are in A, never a foreign-label policy test.
        $pdo->exec("UPDATE variable_value_label_set SET value_label_set_id = (SELECT link.value_label_set_id FROM variable_value_label_set link JOIN variable v ON v.variable_id = link.variable_id JOIN dataset d ON d.dataset_id = v.dataset_id WHERE d.dataset_name = 'A' AND v.source_ordinal = 1) WHERE variable_id = (SELECT v.variable_id FROM variable v JOIN dataset d ON d.dataset_id = v.dataset_id WHERE d.dataset_name = 'A' AND v.source_ordinal = 3)");
        $exporter = $class === SpssAdapter::class ? $adapter : new $class($pdo);
        foreach (['A', 'B', 'A', 'C'] as $name) {
            $expected = match ($name) {
                'A' => $source,
                'B' => $this->groupedExportFixture(4, 3, changed: true),
                default => $this->groupedExportFixture(2, 0, true),
            };
            $before = self::rows($pdo, 'SELECT total_changes() AS changes');
            $pdo->exec('PRAGMA query_only = ON');
            $export = $this->publicExport($exporter, $engine, $name, 'zsav');
            $this->assertGroupedExport($expected, $export, 'zsav');
            self::assertSame($before, self::rows($pdo, 'SELECT total_changes() AS changes'));
            $pdo->exec('PRAGMA query_only = OFF');
        }
        $pdo->beginTransaction();
        // Mutate each grouped child family, scoped to A; same object must re-read them.
        $pdo->exec("UPDATE value_label SET label = 'Updated' WHERE ordinal = 1 AND value_label_set_id IN (SELECT link.value_label_set_id FROM variable_value_label_set link JOIN variable v ON v.variable_id = link.variable_id JOIN dataset d ON d.dataset_id = v.dataset_id WHERE d.dataset_name = 'A' AND v.source_ordinal = 1)");
        $pdo->exec("UPDATE missing_rule SET numeric_upper = 4 WHERE variable_id IN (SELECT v.variable_id FROM variable v JOIN dataset d ON d.dataset_id = v.dataset_id WHERE d.dataset_name = 'A' AND v.source_ordinal = 1)");
        $pdo->exec("INSERT INTO missing_rule (missing_rule_id, variable_id, ordinal, rule_kind, code_kind, numeric_value) SELECT 'new-missing', v.variable_id, 2, 'discrete', 'numeric', 99 FROM variable v JOIN dataset d ON d.dataset_id = v.dataset_id WHERE d.dataset_name = 'A' AND v.source_ordinal = 1");
        $pdo->exec("UPDATE missing_rules SET missing_format = -3 WHERE dataset_name = 'A' AND variable_ordinal = 1");
        $pdo->exec("INSERT INTO missing_rule_values (dataset_name, variable_ordinal, ordinal, value_kind, numeric_value) VALUES ('A', 1, 3, 'numeric', 99)");
        $pdo->exec("UPDATE variable_attribute SET attribute_value = 'updated' WHERE attribute_name = 'Origin' AND array_ordinal = 2 AND variable_id IN (SELECT v.variable_id FROM variable v JOIN dataset d ON d.dataset_id = v.dataset_id WHERE d.dataset_name = 'A' AND v.source_ordinal = 1)");
        $pdo->exec("UPDATE variable SET variable_role = 0, display_width = 13 WHERE dataset_id = (SELECT dataset_id FROM dataset WHERE dataset_name = 'A') AND source_ordinal = 1");
        $pdo->exec("UPDATE value_labels SET label = 'Updated' WHERE dataset_name = 'A' AND variable_ordinal IN (1, 3) AND ordinal = 1");
        $pdo->exec("UPDATE missing_rule_values SET numeric_value = 4 WHERE dataset_name = 'A' AND variable_ordinal = 1 AND ordinal = 2");
        $pdo->exec("UPDATE variable_attributes SET value = 'updated' WHERE dataset_name = 'A' AND variable_ordinal = 1 AND attribute_name = 'Origin' AND ordinal = 2");
        $pdo->exec("UPDATE variable_roles SET role = 0 WHERE dataset_name = 'A' AND variable_ordinal = 1");
        $pdo->exec("UPDATE variable_display_metadata SET display_width = 13 WHERE dataset_name = 'A' AND variable_ordinal = 1");
        $pdo->exec("DELETE FROM variable_set_member WHERE source_ordinal = 2 AND variable_set_id IN (SELECT s.variable_set_id FROM variable_set s JOIN dataset d ON d.dataset_id = s.dataset_id WHERE d.dataset_name = 'A')");
        $pdo->exec("DELETE FROM multiple_response_member WHERE source_ordinal = 2 AND multiple_response_set_id IN (SELECT s.multiple_response_set_id FROM multiple_response_set s JOIN dataset d ON d.dataset_id = s.dataset_id WHERE d.dataset_name = 'A')");
        $pdo->exec("DELETE FROM variable_set_members WHERE dataset_name = 'A' AND member_ordinal = 2");
        $pdo->exec("DELETE FROM multiple_response_set_members WHERE dataset_name = 'A' AND member_ordinal = 2");
        $pdo->commit();
        $pdo->exec('PRAGMA query_only = ON');
        $this->assertGroupedExport($this->groupedExportFixture(4, 3, changed: true), $this->publicExport($exporter, $engine, 'A'), 'sav');
    }

    /** @param class-string<CanonicalWideTableExporter|SqliteWideTableExporter|MySqlWideTableExporter|PostgreSqlWideTableExporter|SpssAdapter> $class */
    #[DataProvider('groupedExporters')]
    public function testGroupedExportRetainsMalformedMissingAndMemberDiagnostics(string $class): void
    {
        $pdo = new PDO('sqlite::memory:');
        $engine = new FakeSpssEngine($this->fixture());
        $adapter = new SpssAdapter($pdo, $engine);
        $adapter->import('fixture.sav', 'A');
        $exporter = $class === SpssAdapter::class ? $adapter : new $class($pdo);
        $canonical = in_array($class, [CanonicalWideTableExporter::class, SpssAdapter::class], true);
        $profile = match ($class) {
            MySqlWideTableExporter::class => 'MySQL-family',
            PostgreSqlWideTableExporter::class => 'PostgreSQL',
            default => 'SQLite',
        };
        $corruptions = $canonical ? [
            ["INSERT INTO missing_rule (missing_rule_id, variable_id, ordinal, rule_kind) SELECT 'bad', variable_id, 2, 'numeric_range' FROM missing_rule", 'Invalid discrete missing rule.'],
            ["UPDATE variable_set_member SET variable_id = 'unknown' WHERE source_ordinal = 1", 'Invalid string value.'],
            ["UPDATE multiple_response_member SET variable_id = 'unknown' WHERE source_ordinal = 1", 'Invalid string value.'],
        ] : [
            ['DELETE FROM missing_rule_values WHERE ordinal = 2', $profile === 'SQLite' ? 'A numeric user-missing rule contains a non-numeric catalogue value.' : "The $profile user-missing rule has an incomplete ordered value list."],
            ['UPDATE variable_set_members SET variable_ordinal = 999 WHERE member_ordinal = 1', "A $profile variable set references an unknown variable."],
            ['UPDATE multiple_response_set_members SET variable_ordinal = 999 WHERE member_ordinal = 1', "A $profile multiple-response set references an unknown variable."],
        ];
        $pdo->exec('PRAGMA foreign_keys = OFF');
        foreach ($corruptions as [$sql, $message]) {
            $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                $this->publicExport($exporter, $engine, 'A');
                self::fail('Malformed grouped metadata was silently discarded.');
            } catch (UnsupportedOperation $exception) {
                self::assertSame(DiagnosticCode::InvalidSourceDataset, $exception->diagnosticCode);
                self::assertSame($message, $exception->getMessage());
            } finally {
                $pdo->rollBack();
            }
        }
    }

    /** @return array{dataset: Dataset, caseCount: int, diagnostics: list<\OpenStatSpec\Core\FidelityDiagnostic>} */
    private function publicExport(CanonicalWideTableExporter|SqliteWideTableExporter|MySqlWideTableExporter|PostgreSqlWideTableExporter|SpssAdapter $exporter, FakeSpssEngine $engine, string $name, string $format = 'sav'): array
    {
        if (!$exporter instanceof SpssAdapter) {
            return $exporter->export($name, $format);
        }
        $target = sys_get_temp_dir() . '/oss-grouped-' . bin2hex(random_bytes(8)) . '.' . $format;
        try {
            $result = $exporter->export($name, $target);
            return ['dataset' => $engine->lastWrite()['dataset'], 'caseCount' => $result->caseCount, 'diagnostics' => $result->diagnostics];
        } finally {
            @unlink($target);
        }
    }

    /** @param array{dataset: Dataset, caseCount: int, diagnostics: list<\OpenStatSpec\Core\FidelityDiagnostic>} $export */
    private function assertGroupedExport(Dataset $expected, array $export, string $format): void
    {
        self::assertSame([], $export['diagnostics']);
        self::assertSame($expected->rowCount(), $export['caseCount']);
        self::assertSame($expected->rows(), $export['dataset']->rows());
        // Compare values and ordering, not object sharing between dictionary entries.
        self::assertSame(array_map('serialize', $expected->variables()), array_map('serialize', $export['dataset']->variables()));
        self::assertSame(serialize($expected->metadata), serialize($export['dataset']->metadata));
        self::assertEquals(new FileTechnicalMetadata(
            sourceFormat: $format,
            recordType: $format === 'zsav' ? '$FL3' : '$FL2',
            sourceVersion: 'OpenStatSpec 0.1',
            provenance: 'Päritolu: küsitlus',
            encoding: 'UTF-8',
            productName: 'OpenStatSpec tööriist',
            compression: $format === 'zsav' ? 2 : 1,
        ), $export['dataset']->technicalMetadata);
    }

    private function groupedExportFixture(int $count, int $setCount, bool $empty = false, bool $changed = false): Dataset
    {
        $base = $this->fixture();
        $variables = [];
        for ($i = 1; $i <= $count; ++$i) {
            $template = $base->variables()[$i === 2 ? 1 : 0];
            $name = $i <= 2 ? $template->name : 'Extra' . $i;
            $variables[] = new VariableMetadata(...array_replace(get_object_vars($template), [
                'name' => $name,
                'dictionaryIndex' => $i,
                'writeFormat' => $i === 2 ? $template->writeFormat : new VariableFormat(5, 12, 2),
                'valueLabels' => new ValueLabelSet($empty || $i === 2 ? [] : [new ValueLabel(7.0, $changed && in_array($i, [1, 3], true) ? 'Updated' : 'Seven'), new ValueLabel(5.0, 'Viis')], [$name]),
                'missingValues' => $empty ? MissingValues::none() : ($changed && $i === 1 ? MissingValues::rangeAndValue(1.0, 4.0, 99.0) : $template->missingValues),
                'role' => $changed && $i === 1 ? VariableRole::INPUT : $template->role,
                'columns' => $changed && $i === 1 ? 13 : $template->columns,
                'attributes' => $empty ? [] : ($i === 2 ? $template->attributes() : [new VariableAttribute($name, 'Origin', ['customer', $changed && $i === 1 ? 'updated' : 'identifier']), new VariableAttribute($name, 'Source', ['õ', ''])]),
            ]));
        }
        $sets = $multiple = [];
        for ($i = 1; $i <= $setCount; ++$i) {
            $members = $i === 1 ? [] : ($changed ? ['Favourite colour'] : ['Favourite colour', 'Respondent ID']);
            $sets[] = new VariableSet('Set' . $i, $members);
            $multiple[] = new MultipleResponseSet('$Set' . $i, MultipleResponseSetType::CATEGORY, $members, 'Set ' . $i);
        }
        return new Dataset(
            new VariableDictionary($variables),
            $empty ? [] : [array_merge([7.0, 'õ'], array_fill(0, $count - 2, 7.0)), array_merge([null, ''], array_fill(0, $count - 2, null))],
            new FileMetadata(
                $empty ? null : $base->metadata->label,
                weightVariableName: $empty ? null : $base->metadata->weightVariableName,
                documents: $empty ? [] : $base->metadata->documents(),
                attributes: $empty ? [] : $base->metadata->attributes(),
                variableSets: $sets,
                multipleResponseSets: $multiple,
            ),
            $base->technicalMetadata,
        );
    }

    private function fixture(string $sourceFormat = 'zsav'): Dataset
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
                sourceFormat: $sourceFormat,
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
