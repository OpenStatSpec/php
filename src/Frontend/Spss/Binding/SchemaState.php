<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Binding;

use OpenStatSpec\Frontend\Spss\Ast\VariableRange;
use OpenStatSpec\Frontend\Spss\Request\InputSchema;
use OpenStatSpec\Transformation\Plan\Operation\ValueLabel;
use OpenStatSpec\Transformation\Plan\Value\Binary64Value;
use OpenStatSpec\Transformation\Plan\Value\StringValue;
use OpenStatSpec\Frontend\Spss\Request\InputVariable;
use OpenStatSpec\Transformation\Diagnostic\SourceSpan;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;

final class SchemaState
{
    /** @var list<InputVariable> */
    private array $variables;

    /** @var array<string, list<InputVariable>> */
    private array $index = [];

    /** @var array<string, list<ValueLabel>> */
    private array $labels = [];

    public function __construct(InputSchema $schema, public readonly bool $officialV03 = false)
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

    /** @return non-empty-list<InputVariable> */
    public function expand(string|VariableRange $name, SourceSpan $span): array
    {
        if (is_string($name)) {
            return [$this->resolve($name, $span)];
        }
        if (!$this->officialV03) {
            throw TransformationFailure::at('spss_syntax_error', '$.source_text', 'TO requires Frontend 0.3.', $span);
        }
        $resolved = $this->expand($name->first, $name->span);
        $first = array_search($resolved[array_key_last($resolved)], $this->variables, true);
        $last = array_search($this->resolve($name->last, $name->span), $this->variables, true);
        if ($first === false || $last === false) {
            throw new \LogicException('Resolved variables must be in dictionary order.');
        }
        if ($first > $last) {
            throw TransformationFailure::at('invalid_variable_range', '$.source_text', 'TO range is reversed.', $span);
        }
        foreach (array_slice($this->variables, $first + 1, $last - $first) as $variable) {
            $resolved[] = $this->resolve($variable->name, $span);
        }
        return $resolved;
    }

    /** @return list<ValueLabel> */
    public function valueLabels(InputVariable $variable): array
    {
        return $this->labels[$variable->name] ?? array_map(
            static fn(array $label): ValueLabel => new ValueLabel(
                $label['value']['type'] === 'binary64'
                    ? Binary64Value::fromBits($label['value']['bits'])
                    : new StringValue($label['value']['value']),
                $label['label'],
            ),
            $variable->valueLabels,
        );
    }

    /** @param list<ValueLabel> $labels */
    public function setValueLabels(InputVariable $variable, array $labels): void
    {
        $this->labels[$variable->name] = $labels;
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
