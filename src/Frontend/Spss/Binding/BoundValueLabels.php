<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Binding;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;
use OpenStatSpec\Transformation\Plan\Operation\ValueLabel;

final readonly class BoundValueLabels implements BoundStatement
{
    /** @param non-empty-list<ValueLabel> $labels */
    public function __construct(
        public string $variable,
        public array $labels,
        public SourceSpan $span,
    ) {}
}
