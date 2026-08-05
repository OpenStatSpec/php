<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model;

/** Removes one variable and its normative metadata from the existing dataset. */
final readonly class DeleteVariableOperation implements TransformationOperation
{
    public function __construct(private string $variable) {}
    public function type(): string
    {
        return 'delete_variable';
    }
    public function sourceVariable(): string
    {
        return $this->variable;
    }
    public function targetVariable(): string
    {
        return $this->variable;
    }
    public function canonicalArray(): array
    {
        return [
            'type' => $this->type(),
            'source_variable' => $this->variable,
            'target_variable' => $this->variable,
        ];
    }
}
