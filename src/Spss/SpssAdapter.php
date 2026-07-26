<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\FidelityPolicy;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\MySqlWideTableImporter;
use OpenStatSpec\Sql\MySqlWideTableExporter;
use OpenStatSpec\Sql\OperationJournal;
use OpenStatSpec\Sql\PostgreSqlWideTableExporter;
use OpenStatSpec\Sql\PostgreSqlWideTableImporter;
use OpenStatSpec\Sql\SqliteWideTableExporter;
use OpenStatSpec\Sql\SqliteWideTableImporter;
use PDO;
use Throwable;

final readonly class SpssAdapter
{
    private Connection $connection;
    private SpssEngine $engine;

    public function __construct(PDO $pdo, ?SpssEngine $engine = null)
    {
        $this->connection = new Connection($pdo);
        $this->engine = $engine ?? new PhpSpssEngine();
    }

    public function pdo(): PDO
    {
        return $this->connection->pdo;
    }

    public function import(string $sourcePath, string $datasetName): SpssImportResult
    {
        $journal = new OperationJournal($this->connection->pdo);
        $operationId = $journal->start('import', null, $sourcePath);
        try {
            if (!in_array($this->spssFormat($sourcePath), ['sav', 'zsav'], true)) {
                throw new UnsupportedOperation(
                    DiagnosticCode::UnsupportedSourceFormat,
                    'This adapter profile supports SAV and ZSAV files only.',
                );
            }
            $source = SpssSourceNormalizer::normalize($this->engine->read($sourcePath));
            match ($this->connection->profile->driverName()) {
                'pgsql' => (new PostgreSqlWideTableImporter($this->connection->pdo))->import($source, $datasetName),
                'mysql' => (new MySqlWideTableImporter($this->connection->pdo))->import($source, $datasetName),
                default => (new SqliteWideTableImporter($this->connection->pdo))->import($source, $datasetName),
            };
            $diagnostics = [];
            $journal->succeed($operationId, $datasetName, $diagnostics);

            return new SpssImportResult($operationId, $datasetName, count($source['data']), $diagnostics);
        } catch (Throwable $exception) {
            $journal->fail($operationId, null, $exception);
            throw $exception;
        }
    }

    /** @param list<string> $allowLoss */
    public function export(string $datasetName, string $targetPath, array $allowLoss = []): SpssExportResult
    {
        $journal = new OperationJournal($this->connection->pdo);
        $operationId = $journal->start('export', $datasetName, $targetPath, $allowLoss);
        $diagnostics = [];
        try {
            $targetFormat = $this->spssFormat($targetPath);
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
            $journal->succeed($operationId, $datasetName, $diagnostics);

            return new SpssExportResult($operationId, $datasetName, $targetPath, $export['caseCount'], $diagnostics, $allowLoss);
        } catch (Throwable $exception) {
            $journal->fail($operationId, $datasetName, $exception, $diagnostics);
            throw $exception;
        }
    }

    private function spssFormat(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

}
