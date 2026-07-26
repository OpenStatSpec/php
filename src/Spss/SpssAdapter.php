<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\SqliteWideTableExporter;
use OpenStatSpec\Sql\SqliteWideTableImporter;
use PDO;

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

    public function import(string $sourcePath, string $datasetName): void
    {
        if (!in_array($this->spssFormat($sourcePath), ['sav', 'zsav'], true)) {
            throw new UnsupportedOperation(
                DiagnosticCode::UnsupportedSourceFormat,
                'This adapter profile supports SAV and ZSAV files only.',
            );
        }
        if ($this->connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new UnsupportedOperation(DiagnosticCode::UnsupportedSqlDriver, 'This import slice currently supports SQLite only.');
        }
        $source = SpssSourceNormalizer::normalize($this->engine->read($sourcePath));
        (new SqliteWideTableImporter($this->connection->pdo))->import($source, $datasetName);
    }

    public function export(string $datasetName, string $targetPath): SpssExportResult
    {
        $targetFormat = $this->spssFormat($targetPath);
        if (!in_array($targetFormat, ['sav', 'zsav'], true)) {
            throw new UnsupportedOperation(
                DiagnosticCode::UnsupportedSourceFormat,
                'This adapter profile exports SAV and ZSAV files only.',
            );
        }
        if ($this->connection->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new UnsupportedOperation(DiagnosticCode::UnsupportedSqlDriver, 'This export slice currently supports SQLite only.');
        }

        $export = (new SqliteWideTableExporter($this->connection->pdo))->export($datasetName, $targetFormat);
        $this->engine->write($targetPath, $export['dataset']);

        return new SpssExportResult($datasetName, $targetPath, $export['caseCount'], $export['diagnostics']);
    }

    private function spssFormat(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}
