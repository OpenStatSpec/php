<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Request;

final readonly class InputVariable
{
    /** @param list<array{value: array<string, string>, label: string}> $valueLabels */
    public function __construct(
        public string $name,
        public string $storageKind,
        public ?string $variableLabel = null,
        public array $valueLabels = [],
        public ?string $formatFamily = null,
        public ?int $width = null,
        public ?int $decimals = null,
        public ?string $measurementLevel = null,
    ) {}
}
