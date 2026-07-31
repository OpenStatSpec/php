<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model;

interface RecodeSelector
{
    public function type(): string;

    /** @return array<string, mixed> */
    public function canonicalArray(): array;
}
