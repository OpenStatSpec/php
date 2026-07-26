<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;
use Throwable;

/**
 * Creates the PostgreSQL side of the strict wide-table contract.
 *
 * This initial slice creates only DDL. It does not insert rows or catalogue
 * records, and therefore does not claim PostgreSQL import/export support.
 */
final readonly class PostgreSqlWideTableImporter
{
    public function __construct(private PDO $pdo) {}

    /** @param array<string, mixed> $source */
    public function createTables(array $source, string $datasetName): PostgreSqlWideTableDefinition
    {
        $variables = $source['variables'] ?? null;
        if (!is_array($variables) || !array_is_list($variables) || $variables === []) {
            throw new UnsupportedOperation(
                DiagnosticCode::InvalidSourceDataset,
                'The source dataset must contain an ordered variable list.',
            );
        }

        $schema = new PostgreSqlSchema($this->pdo);
        // Validate the complete physical mapping before any DDL starts.
        $definition = $schema->wideTableDefinition($datasetName, $variables);

        $this->pdo->beginTransaction();
        try {
            $schema->createCatalog();
            $this->pdo->exec($definition->createSql);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        return $definition;
    }
}
