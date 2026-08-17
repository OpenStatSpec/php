<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Operation;

use OpenStatSpec\Transformation\Plan\Operation;
use OpenStatSpec\Transformation\Plan\PlanContract;
use OpenStatSpec\Transformation\Plan\Recode\RecodeRule;
use OpenStatSpec\Transformation\Plan\Recode\Result;
use OpenStatSpec\Transformation\Plan\TargetMode;

final readonly class RecodeOperation implements Operation
{
    /** @param non-empty-list<RecodeRule> $rules */
    public function __construct(
        public string $source,
        public string $target,
        public TargetMode $targetMode,
        public array $rules,
        public Result $unmatched,
    ) {}

    /** @return array<string, mixed> */
    public function canonicalArray(): array
    {
        return [
            'op' => 'recode',
            'source' => $this->source,
            'target' => $this->target,
            'target_mode' => $this->targetMode->value,
            'rules' => array_map(static fn(RecodeRule $rule): array => $rule->canonicalArray(), $this->rules),
            'unmatched' => $this->unmatched->canonicalArray(),
        ];
    }

    public function minimumContract(): PlanContract
    {
        return PlanContract::V01;
    }
}
