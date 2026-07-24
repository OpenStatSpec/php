<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\PhpSpssEngine;
use OpenStatSpec\Spss\SpssAdapter;
use OpenStatSpec\Tests\Support\FakeSpssEngine;
use RuntimeException;
use PDO;
use PHPUnit\Framework\TestCase;

final class SpssAdapterTest extends TestCase
{
    public function testImportReportsAnUnavailableExternalEngineExplicitly(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        self::assertFalse((new PhpSpssEngine())->isAvailable());

        $adapter = new SpssAdapter(new PDO('sqlite::memory:'));

        try {
            $adapter->import('fixture.sav', 'fixture');
            self::fail('Expected explicit external-engine diagnostic.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::ExternalEngineUnavailable, $exception->diagnosticCode);
        }
    }
    public function testImportCreatesOneWideTableAndRecordsSourceOrder(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:');
        $engine = new FakeSpssEngine([
            'header' => (object) ['fileLabel' => 'Customer survey source'],
            'variables' => [
                (object) ['name' => 'Respondent ID', 'width' => 0, 'print' => [0, 5, 8, 0], 'label' => 'Respondent identifier', 'missingValuesFormat' => -2, 'missingValues' => [1.0, 3.0]],
                (object) ['name' => 'Favourite colour', 'width' => 12, 'print' => [0, 1, 12, 0], 'label' => 'Favourite colour'],
            ],
            'valueLabels' => [(object) ['indexes' => [0], 'labels' => [['value' => 7.0, 'label' => 'Seven']]]],
            'documents' => ['First document line', 'Second document line'],
            'info' => [],
            'data' => [
                ['Respondent ID' => 7, 'Favourite colour' => 'blue'],
                ['Respondent ID' => 8, 'Favourite colour' => 'green'],
            ],
        ]);
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
        self::assertSame([['meta_value' => 'Customer survey source']], self::rows($pdo, 'SELECT meta_value FROM dataset_metadata WHERE dataset_name = "Customer survey"'));
        self::assertSame([['ordinal' => 1, 'text' => 'First document line'], ['ordinal' => 2, 'text' => 'Second document line']], self::rows($pdo, 'SELECT ordinal, text FROM documents WHERE dataset_name = "Customer survey" ORDER BY ordinal'));

        self::assertSame('Customer survey', $result->datasetName);
        self::assertSame('roundtrip.sav', $result->targetPath);
        self::assertSame(2, $result->caseCount);
        self::assertSame('dataset_metadata_not_preserved', $result->diagnostics[0]->code);
        self::assertSame(
            [
                ['name' => 'Respondent ID', 'format' => 5, 'width' => 8, 'decimals' => 0, 'label' => 'Respondent identifier', 'data' => [7.0, 8.0], 'values' => ['7' => 'Seven'], 'missing' => [1.0, 3.0]],
                ['name' => 'Favourite colour', 'format' => 1, 'width' => 12, 'decimals' => 0, 'label' => 'Favourite colour', 'data' => ['blue', 'green'], 'values' => [], 'missing' => []],
            ],
            $engine->lastWrite()['dataset']['variables'],
        );
        self::assertSame([], $engine->lastWrite()['dataset']['header']);
        self::assertArrayNotHasKey('data', $engine->lastWrite()['dataset']);

    }

    public function testPhpSpssEngineImportsConfiguredFixture(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $engineRoot = getenv('OPENSTATSPEC_PHP_SPSS_PATH');
        if (!is_string($engineRoot) || !is_file($engineRoot . '/src/Sav/Reader.php')) {
            self::markTestSkipped('Set OPENSTATSPEC_PHP_SPSS_PATH to a compatible php-spss checkout to run this integration test.');
        }

        spl_autoload_register(static function (string $class) use ($engineRoot): void {
            if (str_starts_with($class, 'SPSS\\')) {
                $path = $engineRoot . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
                if (is_file($path)) {
                    require_once $path;
                }
            }
        });

        $fixture = $engineRoot . '/examples/data.sav';
        self::assertFileExists($fixture);

        $original = (new PhpSpssEngine())->read($fixture);
        $pdo = new PDO('sqlite::memory:');
        $adapter = new SpssAdapter($pdo);
        $adapter->import($fixture, 'php-spss fixture');

        self::assertNotSame(
            [],
            self::rows($pdo, 'SELECT source_name, source_width, format_family, format_width, format_decimals FROM variables WHERE dataset_name = "php-spss fixture" ORDER BY ordinal'),
        );
        self::assertNotSame(
            [],
            self::rows($pdo, 'SELECT * FROM "dataset_php_spss_fixture" ORDER BY "__case_ordinal"'),
        );

        $target = sys_get_temp_dir() . '/openstatspec-readback-' . uniqid('', true) . '.sav';
        try {
            $adapter->export('php-spss fixture', $target);
            $readBack = (new PhpSpssEngine())->read($target);
            self::assertNotSame([], $readBack['variables']);
            self::assertNotSame([], $readBack['data']);
            self::assertSame(count($original['data']), count($readBack['data']));
            self::assertSame(array_keys($original['data'][0]), array_keys($readBack['data'][0]));
        } finally {
            @unlink($target);
        }
    }

    public function testPhpSpssEngineLimitsLongStringWidthTo255(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }
        $engineRoot = getenv('OPENSTATSPEC_PHP_SPSS_PATH');
        if (!is_string($engineRoot) || !is_file($engineRoot . '/src/Sav/Reader.php')) {
            self::markTestSkipped('Set OPENSTATSPEC_PHP_SPSS_PATH to a compatible php-spss checkout.');
        }
        spl_autoload_register(static function (string $class) use ($engineRoot): void {
            if (str_starts_with($class, 'SPSS\\')) {
                $path = $engineRoot . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
                if (is_file($path)) {
                    require_once $path;
                }
            }
        });
        $source = sys_get_temp_dir() . '/openstatspec-long-source-' . uniqid('', true) . '.sav';
        $target = sys_get_temp_dir() . '/openstatspec-long-target-' . uniqid('', true) . '.sav';
        $value = str_repeat('x', 300);
        try {
            $engine = new PhpSpssEngine();
            $engine->write($source, ['header' => [], 'variables' => [['name' => 'Longtext', 'format' => 1, 'width' => 300, 'decimals' => 0, 'label' => 'Long text', 'data' => [$value]]], 'documents' => [], 'info' => []]);
            $adapter = new SpssAdapter(new PDO('sqlite::memory:'));
            $adapter->import($source, 'long string');
            $adapter->export('long string', $target);
            $readBack = $engine->read($target);
            self::assertSame(255, $readBack['variables'][0]->width);
            self::assertNull($readBack['data'][0]['Longtext'] ?? null);
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }
    public function testImportRejectsZsavBeforeReadingOrChangingTheDatabase(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $pdo = new PDO('sqlite::memory:');
        $adapter = new SpssAdapter($pdo, new FakeSpssEngine([
            'header' => null,
            'variables' => [],
            'valueLabels' => [(object) ['indexes' => [0], 'labels' => [['value' => 7.0, 'label' => 'Seven']]]],
            'documents' => [],
            'info' => [],
            'data' => [],
        ]));

        $this->expectException(UnsupportedOperation::class);
        $this->expectExceptionMessage('ZSAV import is not supported');

        try {
            $adapter->import('fixture.zsav', 'fixture');
        } finally {
            self::assertSame([], self::rows($pdo, "SELECT name FROM sqlite_master WHERE type = 'table'"));
        }
    }

    /** @return array<int, array<string, mixed>> */
    private static function rows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Test query failed.');
        }

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

}
