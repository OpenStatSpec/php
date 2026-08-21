<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class FormatTarget
{
    public function __construct(
        public string $variable,
        public string $family,
        public int $width,
        public int $decimals,
        public SourceSpan $variableSpan,
        public SourceSpan $span,
    ) {}
}
