<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Expression;

use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;

final readonly class Comparison implements Predicate
{
    public function __construct(
        public Operand $left,
        public string $operator,
        public Operand $right,
    ) {
        if (!in_array($operator, ['=', '<', '<=', '>', '>='], true)) {
            throw TransformationFailure::at('plan_schema_invalid', '$.operator', 'Unsupported comparison operator.');
        }
    }

    /** @return array{expression: string, left: array<string, mixed>, operator: string, right: array<string, mixed>} */
    public function canonicalArray(): array
    {
        return [
            'expression' => 'comparison',
            'left' => $this->left->canonicalArray(),
            'operator' => $this->operator,
            'right' => $this->right->canonicalArray(),
        ];
    }
}
