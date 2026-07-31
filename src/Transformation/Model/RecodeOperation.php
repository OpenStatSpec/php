<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model;

final readonly class RecodeOperation implements TransformationOperation
{
    /** @param list<RecodeRule> $rules */
    public function __construct(
        private string $sourceVariable,
        private string $targetVariable,
        private array $rules,
    ) {}

    public function type(): string
    {
        return 'recode';
    }

    public function sourceVariable(): string
    {
        return $this->sourceVariable;
    }

    public function targetVariable(): string
    {
        return $this->targetVariable;
    }

    /** @return list<RecodeRule> */
    public function rules(): array
    {
        return $this->rules;
    }

    /** @return array{type: string, source_variable: string, target_variable: string, rules: list<array<string, mixed>>} */
    public function canonicalArray(): array
    {
        return [
            'type' => $this->type(),
            'source_variable' => $this->sourceVariable,
            'target_variable' => $this->targetVariable,
            'rules' => array_map(
                static fn(RecodeRule $rule): array => $rule->canonicalArray(),
                $this->rules,
            ),
        ];
    }
}
