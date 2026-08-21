<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Operation;

use OpenStatSpec\Transformation\Plan\Operation;
use OpenStatSpec\Transformation\Plan\PlanContract;

final readonly class SetMeasurementLevelOperation implements Operation
{
    public function __construct(
        public string $variable,
        public string $level,
    ) {}

    /** @return array{op: string, variable: string, level: string} */
    public function canonicalArray(): array
    {
        return ['op' => 'set_measurement_level', 'variable' => $this->variable, 'level' => $this->level];
    }

    public function minimumContract(): PlanContract
    {
        return PlanContract::V02;
    }
}
