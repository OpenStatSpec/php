<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Value;

final readonly class StringValue implements TypedValue
{
    public function __construct(public string $value) {}

    /** @return array{type: string, value: string} */
    public function canonicalArray(): array
    {
        return ['type' => 'string', 'value' => $this->value];
    }

    public function canonicalKey(): string
    {
        return 'string:' . $this->value;
    }
}
