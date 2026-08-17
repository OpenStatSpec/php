<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Operation;

use OpenStatSpec\Transformation\Plan\Operation;
use OpenStatSpec\Transformation\Plan\PlanContract;

final readonly class ReplaceValueLabelsOperation implements Operation
{
    /** @param non-empty-list<ValueLabel> $labels */
    public function __construct(
        public string $variable,
        public array $labels,
    ) {}

    /** @return array<string, mixed> */
    public function canonicalArray(): array
    {
        return [
            'op' => 'replace_value_labels',
            'variable' => $this->variable,
            'labels' => array_map(static fn(ValueLabel $label): array => $label->canonicalArray(), $this->labels),
        ];
    }

    public function minimumContract(): PlanContract
    {
        return PlanContract::V01;
    }
}
