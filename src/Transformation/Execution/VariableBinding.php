<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

/** @internal Exact normative-catalog binding for one variable. */
final readonly class VariableBinding
{
    public function __construct(
        public string $variableId,
        public string $sourceName,
        public string $physicalName,
        public string $storageKind,
        public int $sourceOrdinal,
        public ?int $declaredStringWidth,
        public bool $persisted = true,
    ) {}
}
