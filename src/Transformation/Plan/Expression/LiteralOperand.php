<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Expression;

use OpenStatSpec\Transformation\Plan\Value\TypedValue;

final readonly class LiteralOperand implements Operand
{
    public function __construct(public TypedValue $value) {}

    /** @return array{kind: string, value: array<string, string>} */
    public function canonicalArray(): array
    {
        return ['kind' => 'literal', 'value' => $this->value->canonicalArray()];
    }
}
