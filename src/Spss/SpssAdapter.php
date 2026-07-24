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

    public function __construct(PDO $pdo)
    {
        $this->connection = new Connection($pdo);
    }

    public function pdo(): PDO
    {
        return $this->connection->pdo;
    }

    public function import(string $sourcePath, string $datasetName): void
    {
        throw new UnsupportedOperation(
            DiagnosticCode::UnsupportedOperation,
            'SAV/ZSAV import is not implemented yet; no data was changed.',
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
