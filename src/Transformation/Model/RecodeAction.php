<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model;

interface RecodeAction
{
    public function type(): string;

    /** @return array<string, mixed> */
    public function canonicalArray(): array;
}
