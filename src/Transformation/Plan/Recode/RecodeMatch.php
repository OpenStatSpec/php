<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Recode;

interface RecodeMatch
{
    /** @return array<string, mixed> */
    public function canonicalArray(): array;
}
