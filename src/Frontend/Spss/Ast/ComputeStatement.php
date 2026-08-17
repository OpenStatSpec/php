<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class ComputeStatement implements Statement
{
    public function __construct(
        public string $target,
        public ExpressionOperand $expression,
        public SourceSpan $span,
    ) {}

    public function line(): int
    {
        return $this->span->startLine;
    }
}
