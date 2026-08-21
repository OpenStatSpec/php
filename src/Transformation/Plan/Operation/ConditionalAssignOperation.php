<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Operation;

use OpenStatSpec\Transformation\Plan\Expression\Operand;
use OpenStatSpec\Transformation\Plan\Expression\Predicate;
use OpenStatSpec\Transformation\Plan\Operation;
use OpenStatSpec\Transformation\Plan\PlanContract;

final readonly class ConditionalAssignOperation implements Operation
{
    public function __construct(
        public Predicate $condition,
        public string $target,
        public Operand $value,
    ) {}

    /** @return array{op: string, condition: array<string, mixed>, target: string, value: array<string, mixed>} */
    public function canonicalArray(): array
    {
        return ['op' => 'conditional_assign', 'condition' => $this->condition->canonicalArray(), 'target' => $this->target, 'value' => $this->value->canonicalArray()];
    }

    public function minimumContract(): PlanContract
    {
        return PlanContract::V02;
    }
}
