<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class Comparison implements Predicate
{
    public function __construct(
        public ExpressionOperand $left,
        public string $operator,
        public ExpressionOperand $right,
        public SourceSpan $span,
    ) {}

    public function span(): SourceSpan
    {
        return $this->span;
    }
}
