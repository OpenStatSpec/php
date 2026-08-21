<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class FormatsStatement implements Statement
{
    /** @param non-empty-list<FormatTarget> $targets */
    public function __construct(public array $targets, public SourceSpan $span) {}

    public function line(): int
    {
        return $this->span->startLine;
    }
}
