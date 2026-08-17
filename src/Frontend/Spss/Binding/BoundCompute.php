<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Binding;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;
use OpenStatSpec\Transformation\Plan\Expression\Operand;
use OpenStatSpec\Transformation\Plan\TargetMode;

final readonly class BoundCompute implements BoundStatement
{
    public function __construct(
        public string $target,
        public TargetMode $targetMode,
        public Operand $value,
        public SourceSpan $span,
    ) {}
}
