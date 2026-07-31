<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

final readonly class ScalarValue
{
    public function __construct(public int|float|string $value) {}
}
