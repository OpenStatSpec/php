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
            'header' => null,
            'variables' => [
                ['name' => 'Respondent ID', 'type' => 'numeric', 'label' => 'Respondent identifier'],
                ['name' => 'Favourite colour', 'type' => 'string', 'label' => 'Favourite colour'],
            ],
            'valueLabels' => [],
            'documents' => [],
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
                ['ordinal' => 1, 'source_name' => 'Respondent ID', 'column_name' => 'respondent_id', 'storage_kind' => 'numeric'],
                ['ordinal' => 2, 'source_name' => 'Favourite colour', 'column_name' => 'favourite_colour', 'storage_kind' => 'string'],
            ],
            self::rows($pdo, 'SELECT ordinal, source_name, column_name, storage_kind FROM variables WHERE dataset_name = "Customer survey" ORDER BY ordinal'),
        );
        $result = $adapter->export('Customer survey', 'roundtrip.sav');

        self::assertSame('Customer survey', $result->datasetName);
        self::assertSame('roundtrip.sav', $result->targetPath);
        self::assertSame(2, $result->caseCount);
        self::assertSame('metadata_not_preserved', $result->diagnostics[0]->code);
        self::assertSame(
            [
                ['name' => 'Respondent ID', 'type' => 'numeric', 'label' => 'Respondent identifier'],
                ['name' => 'Favourite colour', 'type' => 'string', 'label' => 'Favourite colour'],
            ],
            $engine->lastWrite()['dataset']['variables'],
        );
        self::assertSame(
            [
                ['Respondent ID' => 7.0, 'Favourite colour' => 'blue'],
                ['Respondent ID' => 8.0, 'Favourite colour' => 'green'],
            ],
            $engine->lastWrite()['dataset']['data'],
        );

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
            'valueLabels' => [],
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
