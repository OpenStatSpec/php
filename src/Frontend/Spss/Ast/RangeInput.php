<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

final readonly class RangeInput implements RecodeInput
{
    public function __construct(
        public ?ScalarValue $lower,
        public ?ScalarValue $upper,
    ) {}
}
