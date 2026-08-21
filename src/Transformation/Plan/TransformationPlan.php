<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan;

final readonly class TransformationPlan
{
    /** @param non-empty-list<Operation> $operations */
    public function __construct(
        public PlanContract $contract,
        public string $inputAlias,
        public array $operations,
    ) {}

    /** @return array{contract: string, input_alias: string, operations: list<array<string, mixed>>} */
    public function canonicalArray(): array
    {
        return [
            'contract' => $this->contract->value,
            'input_alias' => $this->inputAlias,
            'operations' => array_map(
                static fn(Operation $operation): array => $operation->canonicalArray(),
                $this->operations,
            ),
        ];
    }
}
