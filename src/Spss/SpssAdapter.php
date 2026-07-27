<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\FidelityPolicy;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\MySqlWideTableExporter;
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
    private bool $recordNormativeCatalog;

    public function __construct(PDO $pdo, ?SpssEngine $engine = null, bool $recordNormativeCatalog = true)
    {
        $this->connection = new Connection($pdo);
        $this->engine = $engine ?? new PhpSpssEngine();
        $this->recordNormativeCatalog = $recordNormativeCatalog;
    }

    public function pdo(): PDO
    {
        return $this->connection->pdo;
    }

    /** Upgrade the legacy compatibility catalogue and canonical OpenStatSpec schema in place. */
    public function migrateCatalog(): void
    {
        match ($this->connection->profile->driverName()) {
            'pgsql' => (new PostgreSqlSchema($this->connection->pdo))->createCatalog(),
            'mysql' => (new MySqlSchema($this->connection->pdo))->createCatalog(),
            default => (new SqliteWideTableImporter($this->connection->pdo))->migrateCatalog(),
        };
        (new NormativeCatalog($this->connection->pdo))->createTables();
    }

    public function import(string $sourcePath, string $datasetName): SpssImportResult
    {
        $sourceFormat = $this->spssFormat($sourcePath);
        $journal = new OperationJournal($this->connection->pdo);
        $operationId = $journal->start('import', null, $sourcePath, engineDetails: $this->engine->identity(), sourceFormat: $sourceFormat, recordNormative: $this->recordNormativeCatalog);
        try {
            if (!in_array($sourceFormat, ['sav', 'zsav'], true)) {
                throw new UnsupportedOperation(
                    DiagnosticCode::UnsupportedSourceFormat,
                    'This adapter profile supports SAV and ZSAV files only.',
                );
            }
            $source = SpssSourceNormalizer::normalize($this->engine->read($sourcePath));
            match ($this->connection->profile->driverName()) {
                'pgsql' => (new PostgreSqlWideTableImporter($this->connection->pdo))->import($source, $datasetName, $sourcePath),
                'mysql' => (new MySqlWideTableImporter($this->connection->pdo))->import($source, $datasetName, $sourcePath),
                default => (new SqliteWideTableImporter($this->connection->pdo))->import($source, $datasetName, $sourcePath),
            };
            $diagnostics = [];
            $journal->succeed($operationId, $datasetName, $diagnostics, $this->recordNormativeCatalog);

            return new SpssImportResult($operationId, $datasetName, count($source['data']), $diagnostics);
        } catch (Throwable $exception) {
            $journal->fail($operationId, null, $exception, sourceItem: $sourcePath, recordNormative: $this->recordNormativeCatalog);
            throw $exception;
        }
    }

    /** @param list<string> $allowLoss */
    public function export(string $datasetName, string $targetPath, array $allowLoss = []): SpssExportResult
    {
        $targetFormat = $this->spssFormat($targetPath);
        $journal = new OperationJournal($this->connection->pdo);
        $operationId = $journal->start('export', $datasetName, $targetPath, $allowLoss, $this->engine->identity(), $targetFormat, $this->recordNormativeCatalog);
        $diagnostics = [];
        try {
            if (!in_array($targetFormat, ['sav', 'zsav'], true)) {
                throw new UnsupportedOperation(
                    DiagnosticCode::UnsupportedSourceFormat,
                    'This adapter profile exports SAV and ZSAV files only.',
                );
            }
            $export = match ($this->connection->profile->driverName()) {
                'pgsql' => (new PostgreSqlWideTableExporter($this->connection->pdo))->export($datasetName, $targetFormat),
                'mysql' => (new MySqlWideTableExporter($this->connection->pdo))->export($datasetName, $targetFormat),
                default => (new SqliteWideTableExporter($this->connection->pdo))->export($datasetName, $targetFormat),
            };
            $diagnostics = $export['diagnostics'];
            FidelityPolicy::assertExportAllowed($diagnostics, $allowLoss);
            $this->engine->write($targetPath, $export['dataset']);
            $journal->succeed($operationId, $datasetName, $diagnostics, $this->recordNormativeCatalog);

            return new SpssExportResult($operationId, $datasetName, $targetPath, $export['caseCount'], $diagnostics, $allowLoss);
        } catch (Throwable $exception) {
            $journal->fail($operationId, $datasetName, $exception, $diagnostics, $targetPath, $this->recordNormativeCatalog);
            throw $exception;
        }
    }

    private function spssFormat(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}
