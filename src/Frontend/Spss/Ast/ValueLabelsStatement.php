<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class ValueLabelsStatement implements Statement
{
    /** @param non-empty-list<ValueLabelGroup> $groups */
    public function __construct(
        public int $sourceLine,
        public array $groups,
        public SourceSpan $span,
        public bool $add = false,
    ) {}

    public function line(): int
    {
        return $this->sourceLine;
    }
}
