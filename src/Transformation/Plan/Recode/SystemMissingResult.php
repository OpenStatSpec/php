<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Recode;

final readonly class SystemMissingResult implements Result
{
    /** @return array{kind: string} */
    public function canonicalArray(): array
    {
        return ['kind' => 'system_missing'];
    }
}
