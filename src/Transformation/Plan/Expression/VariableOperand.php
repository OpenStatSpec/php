<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Expression;

final readonly class VariableOperand implements Operand
{
    public function __construct(public string $variable) {}

    /** @return array{kind: string, variable: string} */
    public function canonicalArray(): array
    {
        return ['kind' => 'variable', 'variable' => $this->variable];
    }
}
