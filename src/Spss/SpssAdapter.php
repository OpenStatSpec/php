<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use OpenStatSpec\Core\CapabilityDeclaration;
use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\FidelityPolicy;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\CanonicalCatalogProjection;
use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\MySqlWideTableDefinition;
use OpenStatSpec\Sql\MySqlWideTableExporter;
use OpenStatSpec\Sql\MySqlProfile;
use OpenStatSpec\Sql\MySqlSchema;
use OpenStatSpec\Sql\MySqlWideTableImporter;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Sql\OperationJournal;
use OpenStatSpec\Sql\PostgreSqlWideTableExporter;
use OpenStatSpec\Sql\PostgreSqlWideTableImporter;
use OpenStatSpec\Sql\PostgreSqlSchema;
use OpenStatSpec\Sql\SqliteWideTableExporter;
use OpenStatSpec\Sql\SqliteWideTableImporter;
use PDO;
use Throwable;

final readonly class SpssAdapter
{
    private Connection $connection;
    private SpssEngine $engine;
    private ?\Closure $beforeImportFinalization;

    public function __construct(
        PDO $pdo,
        ?SpssEngine $engine = null,
        ?callable $beforeImportFinalization = null,
    ) {
        $this->connection = new Connection($pdo);
        $this->engine = $engine ?? new PhpSpssEngine();
        $this->beforeImportFinalization = $beforeImportFinalization === null
            ? null
            : \Closure::fromCallable($beforeImportFinalization);
    }

    /** @return array<string, mixed> */
    public function capabilities(): array
    {
        return (new CapabilityDeclaration($this->connection, $this->engine))->toArray();
    }

    public function pdo(): PDO
    {
        return $this->connection->pdo;
    }

    /** Upgrade the legacy compatibility catalogue and canonical OpenStatSpec schema in place. */
    public function migrateCatalog(): void
    {
        $this->connection->assertClaimedSupported();
        CatalogOwnership::ensure($this->connection->pdo);
        match ($this->connection->profile->driverName()) {
            'pgsql' => (new PostgreSqlSchema($this->connection->pdo))->createCatalog(),
            'mysql' => (new MySqlSchema($this->connection->pdo, $this->mySqlProfile()))->createCatalog(),
            default => (new SqliteWideTableImporter($this->connection->pdo))->migrateCatalog(),
        };
        $catalog = new NormativeCatalog($this->connection->pdo);
        $catalog->createTables();
        $this->backfillLegacyDatasets($catalog);
        CatalogOwnership::markCurrentVersion($this->connection->pdo);
    }

    private function backfillLegacyDatasets(NormativeCatalog $catalog): void
    {
        $datasets = $this->connection->pdo->query('SELECT dataset_name FROM datasets ORDER BY dataset_name');
        if ($datasets === false) {
            return;
        }
        while (($datasetName = $datasets->fetchColumn()) !== false) {
            if (!is_string($datasetName) || $catalog->hasDataset($datasetName)) {
                continue;
            }
            $format = $this->legacySourceFormat($datasetName);
            $export = match ($this->connection->profile->driverName()) {
                'pgsql' => (new PostgreSqlWideTableExporter($this->connection->pdo))->export($datasetName, $format),
                'mysql' => (new MySqlWideTableExporter($this->connection->pdo))->export($datasetName, $format),
                default => (new SqliteWideTableExporter($this->connection->pdo))->export($datasetName, $format),
            };
            $this->connection->pdo->beginTransaction();
            try {
                $catalog->storeImportedDataset($datasetName, '', SpssSourceNormalizer::normalize($export['dataset']));
                $this->connection->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->connection->pdo->inTransaction()) {
                    $this->connection->pdo->rollBack();
                }
                throw $exception;
            }
        }
    }

    private function ensureCatalogReady(): void
    {
        try {
            CatalogOwnership::assertReadyForUse($this->connection->pdo);
        } catch (UnsupportedOperation $exception) {
            if ($exception->diagnosticCode !== DiagnosticCode::CatalogMigrationRequired
                || !CatalogOwnership::isFreshPending($this->connection->pdo)
            ) {
                throw $exception;
            }
            $this->migrateCatalog();
            CatalogOwnership::assertReadyForUse($this->connection->pdo);
        }
    }

    private function legacySourceFormat(string $datasetName): string
    {
        $statement = $this->connection->pdo->prepare('SELECT source_format FROM file_technical_metadata WHERE dataset_name = ?');
        if ($statement === false) {
            return 'sav';
        }
        $statement->execute([$datasetName]);
        $format = $statement->fetchColumn();
        return in_array($format, ['sav', 'zsav'], true) ? $format : 'sav';
    }

    /** The optional provenance hash must already verify the bytes read by the engine. */
    public function import(
        string $sourcePath,
        string $datasetName,
        ?string $verifiedSourceSha256 = null,
    ): SpssImportResult {
        $this->assertLogicalSourcePath($sourcePath);
        $verifiedSourceSha256 = NormativeCatalog::validateSourceSha256($verifiedSourceSha256);
        $this->connection->assertClaimedSupported();
        $this->ensureCatalogReady();
        $sourceFormat = $this->spssFormat($sourcePath);
        $journal = new OperationJournal($this->connection->pdo);
        $operationId = $journal->start('import', null, $sourcePath, engineDetails: $this->engine->identity(), sourceFormat: $sourceFormat);
        $mySqlDefinition = null;
        try {
            if (!in_array($sourceFormat, ['sav', 'zsav'], true)) {
                throw new UnsupportedOperation(
                    DiagnosticCode::UnsupportedSourceFormat,
                    'This adapter profile supports SAV and ZSAV files only.',
                );
            }
            $source = SpssSourceNormalizer::normalize($this->engine->read($sourcePath));
            if ($this->connection->profile->driverName() === 'pgsql') {
                (new PostgreSqlWideTableImporter($this->connection->pdo))->import(
                    $source,
                    $datasetName,
                    $sourcePath,
                    $verifiedSourceSha256,
                );
            } elseif ($this->connection->profile->driverName() === 'mysql') {
                $mySqlDefinition = (new MySqlWideTableImporter(
                    $this->connection->pdo,
                    $this->mySqlProfile(),
                ))->import($source, $datasetName, $sourcePath, $verifiedSourceSha256);
            } else {
                (new SqliteWideTableImporter($this->connection->pdo))->import(
                    $source,
                    $datasetName,
                    $sourcePath,
                    $verifiedSourceSha256,
                );
            }
            if ($this->beforeImportFinalization !== null) {
                ($this->beforeImportFinalization)();
            }
            $diagnostics = [];
            $journal->succeed($operationId, $datasetName, $diagnostics);

            return new SpssImportResult($operationId, $datasetName, count($source['data']), $diagnostics);
        } catch (Throwable $exception) {
            $failure = $exception;
            if ($mySqlDefinition instanceof MySqlWideTableDefinition) {
                try {
                    (new MySqlWideTableImporter(
                        $this->connection->pdo,
                        $this->mySqlProfile(),
                    ))->compensateFailure($datasetName, $mySqlDefinition);
                } catch (Throwable $cleanupFailure) {
                    $failure = $cleanupFailure;
                }
            }
            $journal->fail($operationId, null, $failure, sourceItem: $sourcePath);
            throw $failure;
        }
    }

    private function assertLogicalSourcePath(string $sourcePath): void
    {
        if (preg_match(
            '~\A(?:/proc/(?:self|thread-self|[0-9]+)/fd/[0-9]+|/dev/fd/[0-9]+)\z~',
            $sourcePath,
        ) === 1) {
            throw new UnsupportedOperation(
                DiagnosticCode::InvalidSourceDataset,
                'Ephemeral descriptor paths must remain internal to an injected SPSS engine; import requires a logical SAV or ZSAV source path.',
            );
        }
    }

    /** @param list<string> $allowLoss */
    public function export(string $datasetName, string $targetPath, array $allowLoss = []): SpssExportResult
    {
        $this->connection->assertClaimedSupported();
        $this->ensureCatalogReady();
        $targetFormat = $this->spssFormat($targetPath);
        $journal = new OperationJournal($this->connection->pdo);
        $operationId = $journal->start('export', $datasetName, $targetPath, $allowLoss, $this->engine->identity(), $targetFormat);
        $diagnostics = [];
        try {
            if (!in_array($targetFormat, ['sav', 'zsav'], true)) {
                throw new UnsupportedOperation(
                    DiagnosticCode::UnsupportedSourceFormat,
                    'This adapter profile exports SAV and ZSAV files only.',
                );
            }
            (new CanonicalCatalogProjection($this->connection->pdo))->synchronize($datasetName);
            $export = match ($this->connection->profile->driverName()) {
                'pgsql' => (new PostgreSqlWideTableExporter($this->connection->pdo))->export($datasetName, $targetFormat),
                'mysql' => (new MySqlWideTableExporter($this->connection->pdo))->export($datasetName, $targetFormat),
                default => (new SqliteWideTableExporter($this->connection->pdo))->export($datasetName, $targetFormat),
            };
            $diagnostics = $export['diagnostics'];
            FidelityPolicy::assertExportAllowed($diagnostics, $allowLoss);
            $this->engine->write($targetPath, $export['dataset']);
            $journal->succeed($operationId, $datasetName, $diagnostics);

            return new SpssExportResult($operationId, $datasetName, $targetPath, $export['caseCount'], $diagnostics, $allowLoss);
        } catch (Throwable $exception) {
            $journal->fail($operationId, $datasetName, $exception, $diagnostics, $targetPath);
            throw $exception;
        }
    }

    private function spssFormat(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    private function mySqlProfile(): MySqlProfile
    {
        $profile = $this->connection->profile;
        if (!$profile instanceof MySqlProfile) {
            throw new UnsupportedOperation(
                DiagnosticCode::UnsupportedSqlDriver,
                'The active MySQL-family connection did not provide a MySQL-compatible SQL profile.',
            );
        }

        return $profile;
    }
}
