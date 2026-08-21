<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Request;

use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;

final readonly class InputSchema
{
    /** @var non-empty-list<InputVariable> */
    public array $variables;

    /** @param array<mixed> $variables */
    public function __construct(array $variables)
    {
        if ($variables === [] || !array_is_list($variables)) {
            self::schema('$.input_schema.variables', 'Variables must be a non-empty ordered array.');
        }
        $validated = [];
        foreach ($variables as $variable) {
            if (!$variable instanceof InputVariable) {
                self::schema('$.input_schema.variables', 'Every schema variable must be an InputVariable.');
            }
            $validated[] = $variable;
        }
        $this->variables = $validated;
    }

    public static function fromArray(mixed $raw, string $path = '$.input_schema'): self
    {
        if (!is_array($raw) || array_is_list($raw)) {
            self::schema($path, 'Input schema must be an object.');
        }
        $keys = array_keys($raw);
        sort($keys, SORT_STRING);
        if ($keys !== ['variables']) {
            self::schema($path, 'Object has missing or extra members.');
        }
        $variables = $raw['variables'];
        if (!is_array($variables) || !array_is_list($variables) || $variables === []) {
            self::schema($path . '.variables', 'Variables must be a non-empty ordered array.');
        }

        return new self(array_map(
            static fn(mixed $variable, int $index): InputVariable => InputVariable::fromArray(
                $variable,
                $path . '.variables[' . $index . ']',
            ),
            $variables,
            array_keys($variables),
        ));
    }

    private static function schema(string $path, string $message): never
    {
        throw TransformationFailure::at('plan_schema_invalid', $path, $message);
    }
}
