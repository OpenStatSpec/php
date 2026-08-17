<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan;

interface Operation
{
    /** @return array<string, mixed> */
    public function canonicalArray(): array;

    public function minimumContract(): PlanContract;
}
