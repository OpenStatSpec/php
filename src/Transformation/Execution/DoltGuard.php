<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;

/** Fail-closed Dolt guard; it observes repository state but never mutates it. */
final readonly class DoltGuard
{
    public function __construct(private DoltEvidenceReader $reader) {}

    public function beforeExecution(): DoltEvidence
    {
        $evidence = $this->reader->read();
        if (!$evidence->isClean()) {
            throw new UnsupportedOperation(
                DiagnosticCode::SqlProfileOperationUnavailable,
                'Dolt transformations require a clean working set before execution; dirty tables: '
                    . implode(', ', $evidence->dirtyTables()) . '.',
            );
        }

        return $evidence;
    }

    public function afterExecution(DoltEvidence $before): DoltEvidence
    {
        $after = $this->reader->read();
        if ($after->branch() !== $before->branch()) {
            throw new UnsupportedOperation(
                DiagnosticCode::SqlProfileOperationUnavailable,
                'The active Dolt branch changed during transformation execution.',
            );
        }
        if ($after->head() !== $before->head()) {
            throw new UnsupportedOperation(
                DiagnosticCode::SqlProfileOperationUnavailable,
                'Dolt HEAD changed during transformation execution; the executor never commits.',
            );
        }

        return $after;
    }
}
