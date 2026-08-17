<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Operation;

use OpenStatSpec\Transformation\Plan\Operation;
use OpenStatSpec\Transformation\Plan\PlanContract;

final readonly class ExecuteOperation implements Operation
{
    /** @return array{op: string} */
    public function canonicalArray(): array
    {
        return ['op' => 'execute'];
    }

    public function minimumContract(): PlanContract
    {
        return PlanContract::V02;
    }
}
