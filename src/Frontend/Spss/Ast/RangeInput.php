<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class RangeInput implements RecodeInput
{
    public function __construct(
        public ?ScalarValue $lower,
        public ?ScalarValue $upper,
        public SourceSpan $span,
    ) {}

    public function span(): SourceSpan
    {
        return $this->span;
    }
}
