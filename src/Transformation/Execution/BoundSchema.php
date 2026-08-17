<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;

/** @internal Immutable catalog schema visible to one ordered operation. */
final readonly class BoundSchema
{
    /** @param array<string, VariableBinding> $variables */
    public function __construct(public array $variables) {}

    public function variable(string $name, string $path = '$.variable'): VariableBinding
    {
        $variable = $this->variables[$name] ?? null;
        if ($variable === null) {
            throw TransformationFailure::at('unknown_variable', $path, 'Plan variable is not registered in the bound dataset.');
        }

        return $variable;
    }
}
