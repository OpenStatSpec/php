<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class VariableRange
{
    public function __construct(
        public string|self $first,
        public string $last,
        public SourceSpan $span,
    ) {}
}
