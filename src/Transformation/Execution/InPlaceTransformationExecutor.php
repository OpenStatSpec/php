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
        $doltBefore = $guard?->beforeExecution();
        $doltAfter = null;
        $transactionStarted = false;

        try {
            $this->connection->pdo->beginTransaction();
            $transactionStarted = true;
            foreach ($bound->operations as $operation) {
                $this->operationExecutor->execute($operation, $bound->dataset);
            }
            $doltAfter = $doltBefore === null ? null : $guard->afterExecution($doltBefore);
            $applyId = $this->auditWriter->succeed(
                $request,
                $bound->dataset,
                $this->connection->profileName,
                $doltBefore,
                $doltAfter,
            );
            $this->connection->pdo->commit();
            $transactionStarted = false;
        } catch (Throwable $failure) {
            if ($transactionStarted) {
                $this->connection->pdo->rollBack();
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
