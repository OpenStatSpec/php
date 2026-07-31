<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model;

/** Replaces the complete value-label set for one existing variable. */
final readonly class SetValueLabelsOperation implements TransformationOperation
{
    /** @param list<ValueLabel> $labels */
    public function __construct(
        private string $targetVariable,
        private array $labels,
    ) {}

    public function type(): string
    {
        return 'set_value_labels';
    }

    public function sourceVariable(): string
    {
        return $this->targetVariable;
    }

    public function targetVariable(): string
    {
        return $this->targetVariable;
    }

    /** @return list<ValueLabel> */
    public function labels(): array
    {
        return $this->labels;
    }

    /** @return array{type: string, source_variable: string, target_variable: string, replacement: string, labels: list<array<string, mixed>>} */
    public function canonicalArray(): array
    {
        return [
            'type' => $this->type(),
            'source_variable' => $this->targetVariable,
            'target_variable' => $this->targetVariable,
            'replacement' => 'complete',
            'labels' => array_map(
                static fn(ValueLabel $label): array => $label->canonicalArray(),
                $this->labels,
            ),
        ];
    }
}
