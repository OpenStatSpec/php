<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Expression;

interface Operand
{
    /** @return array<string, mixed> */
    public function canonicalArray(): array;
}
