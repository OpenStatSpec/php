<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Operation;

use OpenStatSpec\Transformation\Plan\Operation;
use OpenStatSpec\Transformation\Plan\PlanContract;

final readonly class SetFormatOperation implements Operation
{
    public function __construct(
        public string $variable,
        public string $family,
        public int $width,
        public int $decimals,
    ) {}

    /** @return array{op: string, variable: string, family: string, width: int, decimals: int} */
    public function canonicalArray(): array
    {
        return ['op' => 'set_format', 'variable' => $this->variable, 'family' => $this->family, 'width' => $this->width, 'decimals' => $this->decimals];
    }

    public function minimumContract(): PlanContract
    {
        return PlanContract::V02;
    }
}
