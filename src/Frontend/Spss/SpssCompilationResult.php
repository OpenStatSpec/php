<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

use OpenStatSpec\Transformation\Plan\TransformationPlan;

final readonly class SpssCompilationResult
{
    public function __construct(
        public TransformationPlan $plan,
        public string $sourceHash,
    ) {}
}
