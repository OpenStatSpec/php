<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Transformation\Audit\TransformationAuditWriter;
use OpenStatSpec\Transformation\Plan\PlanCodec;
use Throwable;

/** Applies one official plan atomically to its existing logical dataset and wide table. */
final readonly class InPlaceTransformationExecutor
{
    private PlanPreflight $preflight;
    private InPlaceOperationExecutor $operationExecutor;
    private TransformationAuditWriter $auditWriter;
    private PlanCodec $codec;

    public function __construct(
        private Connection $connection,
        private ?DoltEvidenceReader $doltEvidenceReader = null,
        ?TransformationAuditWriter $auditWriter = null,
        ?PlanPreflight $preflight = null,
        ?InPlaceOperationExecutor $operationExecutor = null,
        ?PlanCodec $codec = null,
    ) {
        $this->codec = $codec ?? new PlanCodec();
        $this->preflight = $preflight ?? new PlanPreflight($connection, $this->codec);
        $this->operationExecutor = $operationExecutor ?? new InPlaceOperationExecutor($connection);
        $this->auditWriter = $auditWriter ?? new TransformationAuditWriter($connection->pdo, $this->codec);
        if (!$this->preflight->isFor($connection->pdo)
            || !$this->operationExecutor->isFor($connection->pdo)
            || !$this->auditWriter->isFor($connection->pdo)
        ) {
            throw new \InvalidArgumentException('Every transformation executor collaborator must use the same PDO connection.');
        }
    }

    public function execute(InPlaceApplyRequest $request): ExecutionResult
    {
        if ($this->connection->pdo->inTransaction()) {
            throw new UnsupportedOperation(
                DiagnosticCode::UnsupportedOperation,
                'An in-place transformation cannot start inside a caller-owned active transaction.',
            );
        }

        $bound = $this->preflight->bind($request);
        $guard = $this->doltGuard();
        $doltBefore = $guard?->beforeExecution($request);
        $doltAfter = null;
        $transactionStarted = false;

        try {
            CheckedPdo::begin($this->connection->pdo);
            $transactionStarted = true;
            foreach ($bound->operations as $operation) {
                $this->operationExecutor->execute($operation, $bound->dataset);
            }
            $doltAfter = $doltBefore === null ? null : $guard->afterExecution($request, $doltBefore);
            $applyId = $this->auditWriter->succeed(
                $request,
                $bound->dataset,
                $this->connection->profileName,
                $doltBefore,
                $doltAfter,
            );
            CheckedPdo::commit($this->connection->pdo);
            $transactionStarted = false;
        } catch (Throwable $failure) {
            if ($transactionStarted) {
                CheckedPdo::rollback($this->connection->pdo, $failure);
            }
            throw $failure;
        }

        return new ExecutionResult(
            $bound->dataset->datasetId,
            $this->codec->hash($request->plan),
            count($request->plan->operations),
            $applyId,
            $doltBefore,
            $doltAfter,
        );
    }

    private function doltGuard(): ?DoltGuard
    {
        if ($this->connection->profileName !== 'dolt') {
            return null;
        }

        return new DoltGuard($this->doltEvidenceReader ?? new PdoDoltEvidenceReader($this->connection->pdo));
    }
}
