<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

final readonly class Program
{
    /** @param list<Statement> $statements */
    public function __construct(public array $statements) {}
}
