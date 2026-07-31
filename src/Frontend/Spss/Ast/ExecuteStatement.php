<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

final readonly class ExecuteStatement implements Statement
{
    public function __construct(public int $sourceLine) {}

    public function line(): int
    {
        return $this->sourceLine;
    }
}
