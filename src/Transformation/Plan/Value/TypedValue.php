<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Value;

interface TypedValue
{
    /** @return array<string, string> */
    public function canonicalArray(): array;

    public function canonicalKey(): string;
}
