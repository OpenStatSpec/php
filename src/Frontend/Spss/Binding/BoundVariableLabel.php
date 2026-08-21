<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Binding;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class BoundVariableLabel implements BoundStatement
{
    public function __construct(
        public string $variable,
        public string $label,
        public SourceSpan $span,
    ) {}
}
