<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model;

final readonly class ValueLabel
{
    public function __construct(
        private ScalarValue $value,
        private string $label,
    ) {}

    public function value(): ScalarValue
    {
        return $this->value;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return array{value: array<string, mixed>, label: string} */
    public function canonicalArray(): array
    {
        return ['value' => $this->value->canonicalArray(), 'label' => $this->label];
    }
}
