<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Binding;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;
use OpenStatSpec\Transformation\Plan\Expression\Operand;
use OpenStatSpec\Transformation\Plan\Expression\Predicate;

final readonly class BoundConditionalAssign implements BoundStatement
{
    public function __construct(
        public Predicate $condition,
        public string $target,
        public Operand $value,
        public SourceSpan $span,
    ) {}
}
