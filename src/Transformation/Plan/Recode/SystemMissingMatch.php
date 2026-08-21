<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Recode;

final readonly class SystemMissingMatch implements RecodeMatch
{
    /** @return array{kind: string} */
    public function canonicalArray(): array
    {
        return ['kind' => 'system_missing'];
    }
}
