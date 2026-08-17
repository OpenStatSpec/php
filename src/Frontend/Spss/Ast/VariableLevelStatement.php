<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class VariableLevelStatement implements Statement
{
    /** @param non-empty-list<VariableLevelGroup> $groups */
    public function __construct(public array $groups, public SourceSpan $span) {}

    public function line(): int
    {
        return $this->span->startLine;
    }
}
