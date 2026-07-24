<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\Connection;
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
        $this->engine->read($sourcePath);
        throw new UnsupportedOperation(
            DiagnosticCode::UnsupportedOperation,
            'SAV/ZSAV parsing succeeded, but SQL import is not implemented yet; no data was changed.',
        );
    }

    public function export(string $datasetName, string $targetPath): void
    {
        throw new UnsupportedOperation(
            DiagnosticCode::UnsupportedOperation,
            'SAV/ZSAV export is not implemented yet; no file was written.',
        );
    }
}
