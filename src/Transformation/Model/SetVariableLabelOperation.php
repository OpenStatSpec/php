<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model;

/** Replaces one variable label; null explicitly clears the label. */
final readonly class SetVariableLabelOperation implements TransformationOperation
{
    public function __construct(
        private string $targetVariable,
        private ?string $label,
    ) {}

    public function type(): string
    {
        return 'set_variable_label';
    }

    public function sourceVariable(): string
    {
        return $this->targetVariable;
    }

    public function targetVariable(): string
    {
        return $this->targetVariable;
    }

    public function label(): ?string
    {
        return $this->label;
    }

    /** @return array{type: string, source_variable: string, target_variable: string, label: ?string} */
    public function canonicalArray(): array
    {
        return [
            'type' => $this->type(),
            'source_variable' => $this->targetVariable,
            'target_variable' => $this->targetVariable,
            'label' => $this->label,
        ];
    }
}
