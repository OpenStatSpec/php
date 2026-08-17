<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

use OpenStatSpec\Transformation\Plan\Operation;

/** @internal One official operation with all physical bindings fixed by preflight. */
final readonly class BoundOperation
{
    public function __construct(
        public Operation $operation,
        public BoundSchema $schema,
        public ?VariableBinding $source = null,
        public ?VariableBinding $target = null,
    ) {}

    public function createsTarget(): bool
    {
        return $this->target !== null && !$this->target->persisted;
    }
}
