<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class NotPredicate implements Predicate
{
    public function __construct(public Predicate $operand, public SourceSpan $span) {}

    public function span(): SourceSpan
    {
        return $this->span;
    }
}
