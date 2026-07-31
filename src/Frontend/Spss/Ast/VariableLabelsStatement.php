<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

final readonly class VariableLabelsStatement implements Statement
{
    /** @param non-empty-array<string, string> $labels */
    public function __construct(public int $sourceLine, public array $labels) {}

    public function line(): int
    {
        return $this->sourceLine;
    }
}
