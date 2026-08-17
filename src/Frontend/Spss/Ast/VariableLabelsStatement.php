<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class VariableLabelsStatement implements Statement
{
    /** @param non-empty-array<string, string> $labels */
    public function __construct(
        public int $sourceLine,
        public array $labels,
        /** @var non-empty-list<VariableLabelAssignment> */
        public array $assignments,
        public SourceSpan $span,
    ) {}

    public function line(): int
    {
        return $this->sourceLine;
    }
}
