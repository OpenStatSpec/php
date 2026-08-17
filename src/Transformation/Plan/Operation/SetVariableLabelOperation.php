<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Operation;

use OpenStatSpec\Transformation\Plan\Operation;
use OpenStatSpec\Transformation\Plan\PlanContract;

final readonly class SetVariableLabelOperation implements Operation
{
    public function __construct(
        public string $variable,
        public string $label,
    ) {}

    /** @return array{op: string, variable: string, label: string} */
    public function canonicalArray(): array
    {
        return ['op' => 'set_variable_label', 'variable' => $this->variable, 'label' => $this->label];
    }

    public function minimumContract(): PlanContract
    {
        return PlanContract::V01;
    }
}
