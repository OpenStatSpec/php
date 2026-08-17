<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Binding;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class BoundFormat implements BoundStatement
{
    public function __construct(
        public string $variable,
        public string $family,
        public int $width,
        public int $decimals,
        public SourceSpan $span,
    ) {}
}
