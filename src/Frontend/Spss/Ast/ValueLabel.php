<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class ValueLabel
{
    public function __construct(
        public ScalarValue $value,
        public string $label,
        public SourceSpan $span,
    ) {}
}
