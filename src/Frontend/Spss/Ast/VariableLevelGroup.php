<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class VariableLevelGroup
{
    /** @param non-empty-list<string> $variables */
    public function __construct(
        public array $variables,
        public string $level,
        public SourceSpan $span,
    ) {}
}
