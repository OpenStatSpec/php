<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Expression;

use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;

final readonly class BooleanPredicate implements Predicate
{
    /** @var non-empty-list<Predicate> */
    public array $operands;

    public function __construct(public string $operator, Predicate ...$operands)
    {
        if (!in_array($operator, ['and', 'or'], true)) {
            throw TransformationFailure::at('plan_schema_invalid', '$.operator', 'Boolean operator must be and or or.');
        }
        if (count($operands) < 2) {
            throw TransformationFailure::at('plan_schema_invalid', '$.operands', 'A boolean expression requires at least two operands.');
        }
        foreach ($operands as $operand) {
            if ($operand instanceof self && $operand->operator === $operator) {
                throw TransformationFailure::at(
                    'noncanonical_boolean_shape',
                    '$.operands',
                    'A same-operator boolean chain must be flattened in source order.',
                );
            }
        }

        $this->operands = array_values($operands);
    }

    /** @return array{expression: string, operator: string, operands: list<array<string, mixed>>} */
    public function canonicalArray(): array
    {
        return [
            'expression' => 'boolean',
            'operator' => $this->operator,
            'operands' => array_map(static fn(Predicate $operand): array => $operand->canonicalArray(), $this->operands),
        ];
    }
}
