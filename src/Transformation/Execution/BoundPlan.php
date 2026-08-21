<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

use OpenStatSpec\Transformation\Plan\TransformationPlan;

/** @internal Complete immutable result of mutation-free apply preflight. */
final readonly class BoundPlan
{
    /** @param non-empty-list<BoundOperation> $operations */
    public function __construct(
        public TransformationPlan $plan,
        public DatasetBinding $dataset,
        public array $operations,
        public BoundSchema $finalSchema,
    ) {}
}
