<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class VariableLabelAssignment
{
    public function __construct(
        public string|VariableRange $variable,
        public string $label,
        public SourceSpan $variableSpan,
        public SourceSpan $span,
    ) {}
}
