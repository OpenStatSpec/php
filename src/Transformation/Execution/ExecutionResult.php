<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

final readonly class ExecutionResult
{
    public function __construct(
        private string $datasetId,
        private string $planHash,
        private int $operationCount,
        private ?string $auditOperationId,
        private ?DoltEvidence $doltBefore,
        private ?DoltEvidence $doltAfter,
    ) {}

    public function datasetId(): string
    {
        return $this->datasetId;
    }

    public function planHash(): string
    {
        return $this->planHash;
    }

    public function operationCount(): int
    {
        return $this->operationCount;
    }

    public function auditOperationId(): ?string
    {
        return $this->auditOperationId;
    }

    public function doltBefore(): ?DoltEvidence
    {
        return $this->doltBefore;
    }

    public function doltAfter(): ?DoltEvidence
    {
        return $this->doltAfter;
    }
}
