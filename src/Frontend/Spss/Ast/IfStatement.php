<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class IfStatement implements Statement
{
    public function __construct(
        public Predicate $predicate,
        public string $target,
        public SourceSpan $targetSpan,
        public ExpressionOperand $expression,
        public SourceSpan $span,
    ) {}

    public function line(): int
    {
        return $this->span->startLine;
    }
}
