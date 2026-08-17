<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Operation;

use OpenStatSpec\Transformation\Plan\Value\TypedValue;

final readonly class ValueLabel
{
    public function __construct(
        public TypedValue $value,
        public string $label,
    ) {}

    /** @return array{value: array<string, string>, label: string} */
    public function canonicalArray(): array
    {
        return ['value' => $this->value->canonicalArray(), 'label' => $this->label];
    }
}
