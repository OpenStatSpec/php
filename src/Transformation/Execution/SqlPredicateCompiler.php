<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

use OpenStatSpec\Sql\PdoSqlProfile;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Plan\Expression\BooleanPredicate;
use OpenStatSpec\Transformation\Plan\Expression\Comparison;
use OpenStatSpec\Transformation\Plan\Expression\LiteralOperand;
use OpenStatSpec\Transformation\Plan\Expression\Operand;
use OpenStatSpec\Transformation\Plan\Expression\Predicate;
use OpenStatSpec\Transformation\Plan\Expression\VariableOperand;
use OpenStatSpec\Transformation\Plan\Value\Binary64Value;

/** @internal Deterministic, parameterized compiler for the bounded predicate subset. */
final readonly class SqlPredicateCompiler
{
    public function __construct(private PdoSqlProfile $profile) {}

    /** @param list<float|string|null> $parameters */
    public function compile(Predicate $predicate, BoundSchema $schema, array &$parameters): string
    {
        return $this->predicate($predicate, $schema, $parameters);
    }

    /** @param list<float|string|null> $parameters */
    public function operand(Operand $operand, BoundSchema $schema, array &$parameters): string
    {
        if ($operand instanceof VariableOperand) {
            return $this->profile->quoteIdentifier(
                $schema->variable($operand->variable)->physicalName,
            );
        }
        if ($operand instanceof LiteralOperand && $operand->value instanceof Binary64Value) {
            $parameters[] = $operand->value->number();
            return '?';
        }

        throw TransformationFailure::at('expression_type_unsupported', '$.value', 'Only numeric SQL operands are supported.');
    }

    /** @param list<float|string|null> $parameters */
    private function predicate(Predicate $predicate, BoundSchema $schema, array &$parameters): string
    {
        return match (true) {
            $predicate instanceof Comparison => $this->comparison($predicate, $schema, $parameters),
            $predicate instanceof BooleanPredicate => $this->boolean($predicate, $schema, $parameters),
            default => throw TransformationFailure::at('plan_schema_invalid', '$.condition', 'Unsupported predicate type.'),
        };
    }

    /** @param list<float|string|null> $parameters */
    private function comparison(Comparison $comparison, BoundSchema $schema, array &$parameters): string
    {
        $left = $this->operand($comparison->left, $schema, $parameters);
        $right = $this->operand($comparison->right, $schema, $parameters);

        return '(' . $left . ' ' . $comparison->operator . ' ' . $right . ')';
    }

    /** @param list<float|string|null> $parameters */
    private function boolean(BooleanPredicate $predicate, BoundSchema $schema, array &$parameters): string
    {
        $children = [];
        foreach ($predicate->operands as $child) {
            $children[] = $this->predicate($child, $schema, $parameters);
        }

        return '(' . implode(
            ' ' . strtoupper($predicate->operator) . ' ',
            $children,
        ) . ')';
    }
}
