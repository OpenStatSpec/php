<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Binding;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class BoundMeasurementLevel implements BoundStatement
{
    public function __construct(
        public string $variable,
        public string $level,
        public SourceSpan $span,
    ) {}
}
