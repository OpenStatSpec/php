<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Plan;

use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Plan\Expression\BooleanPredicate;
use OpenStatSpec\Transformation\Plan\Expression\Comparison;
use OpenStatSpec\Transformation\Plan\Expression\LiteralOperand;
use OpenStatSpec\Transformation\Plan\Expression\VariableOperand;
use OpenStatSpec\Transformation\Plan\Value\Binary64Value;
use PHPUnit\Framework\TestCase;

final class BooleanPredicateTest extends TestCase
{
    public function testRejectsDirectSameOperatorBooleanChild(): void
    {
        try {
            new BooleanPredicate(
                'and',
                new BooleanPredicate('and', $this->comparison('source_a'), $this->comparison('source_b')),
                $this->comparison('source_c'),
            );
            self::fail('Expected noncanonical_boolean_shape.');
        } catch (TransformationFailure $failure) {
            self::assertSame('noncanonical_boolean_shape', $failure->diagnosticCode());
        }
    }

    private function comparison(string $variable): Comparison
    {
        return new Comparison(
            new VariableOperand($variable),
            '=',
            new LiteralOperand(Binary64Value::fromBits('3ff0000000000000')),
        );
    }
}
