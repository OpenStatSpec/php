<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\FidelityDiagnostic;
use OpenStatSpec\Core\FidelitySeverity;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;
use PDOException;
use Throwable;

/** Records import/export attempts, including failures before a dataset exists. */
final readonly class OperationJournal
{
    public function __construct(private PDO $pdo) {}

    /**
     * @param list<string> $allowLoss
     * @param array<string, string|null> $engineDetails
     */
    public function start(
        string $direction,
        ?string $datasetName,
        ?string $targetPath,
        array $allowLoss = [],
        array $engineDetails = [],
        ?string $sourceFormat = null,
        bool $recordNormative = true,
    ): string {
        CatalogOwnership::ensure($this->pdo);
        $this->createLegacyTables();
        $operationId = $this->operationId();
        $statement = $this->pdo->prepare(
            'INSERT INTO operation_catalog (operation_id, direction, status, dataset_name, target_path, allow_loss, engine_details, source_format, started_at, failure_code, failure_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([$operationId, $direction, 'running', $datasetName, $targetPath, $this->json($allowLoss), $this->json($engineDetails), $sourceFormat, $this->timestamp(), null, null]);

        if ($recordNormative) {
            (new NormativeCatalog($this->pdo))->createTables();
            $this->statement('INSERT INTO operation (operation_id, operation_kind, status, source_format, started_at, completed_at) VALUES (?, ?, ?, ?, ?, ?)')->execute([
                $operationId, $direction, 'started', $sourceFormat, $this->timestamp(), null,
            ]);
        }

        return $operationId;
    }

    /** @param list<FidelityDiagnostic> $diagnostics */
    public function succeed(string $operationId, ?string $datasetName, array $diagnostics, bool $recordNormative = true): void
    {
        $statement = $this->pdo->prepare('UPDATE operation_catalog SET status = ?, dataset_name = ?, completed_at = ? WHERE operation_id = ?');
        $statement->execute(['succeeded', $datasetName, $this->timestamp(), $operationId]);
        if ($recordNormative) {
            $this->updateNormativeOperation($operationId, 'succeeded');
        }
        $this->storeFidelityDiagnostics($operationId, $datasetName, $diagnostics, $recordNormative);
    }

    /** @param list<FidelityDiagnostic> $diagnostics */
    public function fail(
        string $operationId,
        ?string $datasetName,
        Throwable $exception,
        array $diagnostics = [],
        ?string $sourceItem = null,
        bool $recordNormative = true,
    ): void {
        $failureCode = $exception instanceof UnsupportedOperation ? $exception->diagnosticCode->value : 'operation_failed';
        $statement = $this->pdo->prepare('UPDATE operation_catalog SET status = ?, dataset_name = ?, completed_at = ?, failure_code = ?, failure_message = ? WHERE operation_id = ?');
        $statement->execute(['failed', $datasetName, $this->timestamp(), $failureCode, $exception->getMessage(), $operationId]);
        if ($recordNormative) {
            $this->updateNormativeOperation($operationId, 'failed');
        }

        if (!array_any($diagnostics, static fn(FidelityDiagnostic $diagnostic): bool => $diagnostic->code === $failureCode)) {
            $diagnostics[] = new FidelityDiagnostic(
                $failureCode,
                $exception->getMessage(),
                FidelitySeverity::Error,
                ['exception_class' => $exception::class],
                $sourceItem,
            );
        }
        $this->storeFidelityDiagnostics($operationId, $datasetName, $diagnostics, $recordNormative);
    }

    private function createLegacyTables(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS operation_catalog (operation_id VARCHAR(36) NOT NULL PRIMARY KEY, direction VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, dataset_name TEXT NULL, target_path TEXT NULL, allow_loss TEXT NOT NULL, engine_details VARCHAR(8192) NOT NULL DEFAULT '{}', source_format VARCHAR(16) NULL, started_at TIMESTAMP NULL, completed_at TIMESTAMP NULL, failure_code VARCHAR(96) NULL, failure_message TEXT NULL)");
        $this->ensureColumn('operation_catalog', 'engine_details', "VARCHAR(8192) NOT NULL DEFAULT '{}'");
        $this->ensureColumn('operation_catalog', 'source_format', 'VARCHAR(16) NULL');
        $this->ensureColumn('operation_catalog', 'started_at', 'TIMESTAMP NULL');
        $this->ensureColumn('operation_catalog', 'completed_at', 'TIMESTAMP NULL');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS fidelity_event_catalog (operation_id VARCHAR(36) NOT NULL, ordinal BIGINT NOT NULL, dataset_name TEXT NULL, direction VARCHAR(16) NOT NULL, severity VARCHAR(16) NOT NULL, code VARCHAR(96) NOT NULL, source_item TEXT NULL, message TEXT NOT NULL, details TEXT NOT NULL, created_at TIMESTAMP NOT NULL, PRIMARY KEY (operation_id, ordinal))');
        $this->ensureColumn('fidelity_event_catalog', 'direction', 'VARCHAR(16) NULL');
        $this->ensureColumn('fidelity_event_catalog', 'source_item', 'TEXT NULL');
        $this->ensureColumn('fidelity_event_catalog', 'created_at', 'TIMESTAMP NULL');
    }

    /** @param list<FidelityDiagnostic> $diagnostics */
    private function storeFidelityDiagnostics(string $operationId, ?string $datasetName, array $diagnostics, bool $recordNormative): void
    {
        if ($diagnostics === []) {
            return;
        }

        $this->pdo->prepare('DELETE FROM fidelity_event_catalog WHERE operation_id = ?')->execute([$operationId]);
        $direction = $this->statement('SELECT direction FROM operation_catalog WHERE operation_id = ?');
        $direction->execute([$operationId]);
        $operationDirection = $direction->fetchColumn();
        $legacy = $this->statement('INSERT INTO fidelity_event_catalog (operation_id, ordinal, dataset_name, direction, severity, code, source_item, message, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $datasetId = $recordNormative ? $this->datasetId($datasetName) : null;
        $normative = $recordNormative ? $this->normativeStatement('INSERT INTO fidelity_event (fidelity_event_id, operation_id, dataset_id, direction, severity, event_code, source_item, detail_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)') : null;
        foreach ($diagnostics as $ordinal => $diagnostic) {
            $legacy->execute([$operationId, $ordinal + 1, $datasetName, $operationDirection, $diagnostic->severity->value, $diagnostic->code, $diagnostic->sourceItem, $diagnostic->message, $this->json($diagnostic->details), $this->timestamp()]);
            if ($normative !== null) {
                $normative->execute([NormativeCatalog::uuid(), $operationId, $datasetId, $operationDirection, $diagnostic->severity->value, $diagnostic->code, $diagnostic->sourceItem, $this->json($diagnostic->details), $this->timestamp()]);
            }
        }
    }

    private function updateNormativeOperation(string $operationId, string $status): void
    {
        $statement = $this->normativeStatement('UPDATE operation SET status = ?, completed_at = ? WHERE operation_id = ?');
        $statement?->execute([$status, $this->timestamp(), $operationId]);
    }

    private function datasetId(?string $datasetName): ?string
    {
        if ($datasetName === null) {
            return null;
        }
        $statement = $this->normativeStatement('SELECT dataset_id FROM dataset WHERE dataset_name = ?');
        if ($statement === null) {
            return null;
        }
        $statement->execute([$datasetName]);
        $datasetId = $statement->fetchColumn();
        return is_string($datasetId) ? $datasetId : null;
    }

    private function normativeStatement(string $sql): ?\PDOStatement
    {
        try {
            return $this->statement($sql);
        } catch (PDOException) {
            return null;
        }
    }

    private function statement(string $sql): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new PDOException('Could not prepare operation journal statement.');
        }
        return $statement;
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        try {
            $this->pdo->query('SELECT ' . $column . ' FROM ' . $table . ' WHERE 1 = 0');
        } catch (PDOException) {
            $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    /** @param array<mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function timestamp(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function operationId(): string
    {
        return NormativeCatalog::uuid();
    }
}
