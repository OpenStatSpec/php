<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Recode;

final readonly class CopyResult implements Result
{
    /** @return array{kind: string} */
    public function canonicalArray(): array
    {
        return ['kind' => 'copy'];
    }
}
