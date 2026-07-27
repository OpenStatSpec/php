<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\FidelityDiagnostic;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;
use PDOException;
use Throwable;

/**
 * Records import/export attempts independently of a dataset catalogue entry.
 *
 * A failed source preflight therefore remains observable even though no target
 * dataset was created. The deliberately small SQL subset works with SQLite,
 * PostgreSQL, MySQL, and MariaDB through PDO.
 */
final readonly class OperationJournal
{
    public function __construct(private PDO $pdo) {}
    /**
     * @param list<string> $allowLoss
     * @param array<string, string|null> $engineDetails
     */
    public function start(string $direction, ?string $datasetName, ?string $targetPath, array $allowLoss = [], array $engineDetails = []): string
    {
        $this->createTables();
        $operationId = $this->operationId();
        $statement = $this->pdo->prepare(
            'INSERT INTO operation_catalog (operation_id, direction, status, dataset_name, target_path, allow_loss, engine_details, failure_code, failure_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([$operationId, $direction, 'running', $datasetName, $targetPath, $this->json($allowLoss), $this->json($engineDetails), null, null]);

        return $operationId;
    }

    /** @param list<FidelityDiagnostic> $diagnostics */
    public function succeed(string $operationId, ?string $datasetName, array $diagnostics): void
    {
        $statement = $this->pdo->prepare('UPDATE operation_catalog SET status = ?, dataset_name = ? WHERE operation_id = ?');
        $statement->execute(['succeeded', $datasetName, $operationId]);
        $this->storeFidelityDiagnostics($operationId, $datasetName, $diagnostics);
    }

    /** @param list<FidelityDiagnostic> $diagnostics */
    public function fail(string $operationId, ?string $datasetName, Throwable $exception, array $diagnostics = []): void
    {
        $failureCode = $exception instanceof UnsupportedOperation ? $exception->diagnosticCode->value : 'operation_failed';
        $statement = $this->pdo->prepare('UPDATE operation_catalog SET status = ?, dataset_name = ?, failure_code = ?, failure_message = ? WHERE operation_id = ?');
        $statement->execute(['failed', $datasetName, $failureCode, $exception->getMessage(), $operationId]);
        $this->storeFidelityDiagnostics($operationId, $datasetName, $diagnostics);
    }

    private function createTables(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS operation_catalog (operation_id VARCHAR(36) NOT NULL PRIMARY KEY, direction VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, dataset_name TEXT NULL, target_path TEXT NULL, allow_loss TEXT NOT NULL, engine_details VARCHAR(8192) NOT NULL DEFAULT '{}', failure_code VARCHAR(96) NULL, failure_message TEXT NULL)");
        try {
            $this->pdo->query('SELECT engine_details FROM operation_catalog WHERE 1 = 0');
        } catch (PDOException) {
            $this->pdo->exec("ALTER TABLE operation_catalog ADD COLUMN engine_details VARCHAR(8192) NOT NULL DEFAULT '{}'");
        }
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS fidelity_event_catalog (operation_id VARCHAR(36) NOT NULL, ordinal BIGINT NOT NULL, dataset_name TEXT NULL, severity VARCHAR(16) NOT NULL, code VARCHAR(96) NOT NULL, message TEXT NOT NULL, details TEXT NOT NULL, PRIMARY KEY (operation_id, ordinal))');
    }

    /** @param list<FidelityDiagnostic> $diagnostics */
    private function storeFidelityDiagnostics(string $operationId, ?string $datasetName, array $diagnostics): void
    {
        if ($diagnostics === []) {
            return;
        }

        $this->pdo->prepare('DELETE FROM fidelity_event_catalog WHERE operation_id = ?')->execute([$operationId]);
        $statement = $this->pdo->prepare('INSERT INTO fidelity_event_catalog (operation_id, ordinal, dataset_name, severity, code, message, details) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($diagnostics as $ordinal => $diagnostic) {
            $statement->execute([
                $operationId,
                $ordinal + 1,
                $datasetName,
                $diagnostic->severity->value,
                $diagnostic->code,
                $diagnostic->message,
                $this->json($diagnostic->details),
            ]);
        }
    }

    /** @param array<mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function operationId(): string
    {
        $hex = bin2hex(random_bytes(16));

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
