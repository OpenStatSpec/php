<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Recode;

use OpenStatSpec\Transformation\Plan\Value\Binary64Value;

final readonly class RangeMatch implements RecodeMatch
{
    public function __construct(
        public Binary64Value $lower,
        public Binary64Value $upper,
    ) {}

    /** @return array{kind: string, lower: array<string, string>, upper: array<string, string>} */
    public function canonicalArray(): array
    {
        return [
            'kind' => 'range',
            'lower' => $this->lower->canonicalArray(),
            'upper' => $this->upper->canonicalArray(),
        ];
    }
}
