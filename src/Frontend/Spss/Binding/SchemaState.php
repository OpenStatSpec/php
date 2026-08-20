<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Binding;

use OpenStatSpec\Frontend\Spss\Request\InputSchema;
use OpenStatSpec\Frontend\Spss\Request\InputVariable;
use OpenStatSpec\Transformation\Diagnostic\SourceSpan;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;

final class SchemaState
{
    /** @var list<InputVariable> */
    private array $variables;

    /** @var array<string, list<InputVariable>> */
    private array $index = [];

    public function __construct(InputSchema $schema)
    {
        $this->variables = $schema->variables;
        foreach ($this->variables as $variable) {
            $this->index[$this->key($variable->name)][] = $variable;
        }
    }

    public function resolve(
        string $name,
        SourceSpan $span,
        string $code = 'unknown_variable',
    ): InputVariable {
        $matches = $this->index[$this->key($name)] ?? [];
        if (count($matches) !== 1) {
            throw TransformationFailure::at(
                $code,
                '$.source_text',
                sprintf('Variable %s does not resolve uniquely in the current schema.', $name),
                $span,
            );
        }

        return $matches[0];
    }

    public function find(string $name, SourceSpan $span): ?InputVariable
    {
        $matches = $this->index[$this->key($name)] ?? [];
        if (count($matches) > 1) {
            return $this->resolve($name, $span);
        }

        return $matches[0] ?? null;
    }

    public function contains(string $name): bool
    {
        return isset($this->index[$this->key($name)]);
    }

    public function addNumeric(string $name): void
    {
        $variable = new InputVariable($name, 'numeric');
        $this->variables[] = $variable;
        $this->index[$this->key($name)][] = $variable;
    }

    private function key(string $name): string
    {
        return mb_strtolower($name, 'UTF-8');
    }
}
