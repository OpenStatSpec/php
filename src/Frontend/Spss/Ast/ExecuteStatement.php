<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class ExecuteStatement implements Statement
{
    public function __construct(public int $sourceLine, public SourceSpan $span) {}

    public function line(): int
    {
        return $this->sourceLine;
    }
}
