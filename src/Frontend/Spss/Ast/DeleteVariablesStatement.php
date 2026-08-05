<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

final readonly class DeleteVariablesStatement implements Statement
{
    /** @param list<string> $variables */
    public function __construct(
        public int $lineNumber,
        public array $variables,
    ) {}

    public function line(): int
    {
        return $this->lineNumber;
    }
}
