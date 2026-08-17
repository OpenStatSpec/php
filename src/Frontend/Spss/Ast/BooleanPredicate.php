<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class BooleanPredicate implements Predicate
{
    /** @param non-empty-list<Predicate> $operands */
    public function __construct(
        public string $operator,
        public array $operands,
        public SourceSpan $span,
    ) {}

    public function span(): SourceSpan
    {
        return $this->span;
    }
}
