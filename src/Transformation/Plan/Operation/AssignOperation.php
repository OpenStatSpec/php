<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Operation;

use OpenStatSpec\Transformation\Plan\Expression\Operand;
use OpenStatSpec\Transformation\Plan\Operation;
use OpenStatSpec\Transformation\Plan\PlanContract;
use OpenStatSpec\Transformation\Plan\TargetMode;

final readonly class AssignOperation implements Operation
{
    public function __construct(
        public string $target,
        public TargetMode $targetMode,
        public Operand $value,
    ) {}

    /** @return array{op: string, target: string, target_mode: string, value: array<string, mixed>} */
    public function canonicalArray(): array
    {
        return ['op' => 'assign', 'target' => $this->target, 'target_mode' => $this->targetMode->value, 'value' => $this->value->canonicalArray()];
    }

    public function minimumContract(): PlanContract
    {
        return PlanContract::V02;
    }
}
