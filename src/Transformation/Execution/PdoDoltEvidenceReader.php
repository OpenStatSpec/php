<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;
use PDOException;

/** Reads Dolt state without checking out, branching, committing, or staging. */
final readonly class PdoDoltEvidenceReader implements DoltEvidenceReader
{
    public function __construct(private PDO $pdo) {}

    public function read(): DoltEvidence
    {
        try {
            $identity = $this->pdo->query("SELECT active_branch() AS branch_name, dolt_hashof('HEAD') AS head_hash");
            $row = $identity === false ? false : $identity->fetch(PDO::FETCH_ASSOC);
            $status = $this->pdo->query('SELECT table_name FROM dolt_status ORDER BY table_name');
            $tables = $status === false ? false : $status->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $exception) {
            throw new UnsupportedOperation(
                DiagnosticCode::SqlProfileOperationUnavailable,
                'The Dolt branch, HEAD, and working-set evidence could not be read: ' . $exception->getMessage(),
            );
        }

        $branch = is_array($row) ? $row['branch_name'] ?? null : null;
        $head = is_array($row) ? $row['head_hash'] ?? null : null;
        if (!is_string($branch) || $branch === '' || !is_string($head) || $head === '' || !is_array($tables)) {
            throw new UnsupportedOperation(
                DiagnosticCode::SqlProfileOperationUnavailable,
                'The Dolt branch, HEAD, or working-set evidence was malformed.',
            );
        }

        $dirtyTables = array_values(array_unique(array_map(
            static fn(mixed $table): string => (string) $table,
            $tables,
        )));

        return new DoltEvidence($branch, $head, $dirtyTables);
    }
}
