<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Conformance;

use OpenStatSpec\Tests\Support\SpecificationManifest;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Plan\PlanCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransformationPlan01Test extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function cases(): iterable
    {
        foreach (SpecificationManifest::load('conformance/transformation-plan-0.1.json')['cases'] as $case) {
            yield $case['id'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testOfficialPlan01(array $case): void
    {
        if (is_string($case['expected_error'])) {
            try {
                (new PlanCodec())->fromArray($case['plan']);
                self::fail('Expected ' . $case['expected_error']);
            } catch (TransformationFailure $failure) {
                self::assertSame($case['expected_error'], $failure->diagnosticCode());
            }
            return;
        }

        $plan = (new PlanCodec())->fromArray($case['plan']);
        self::assertSame($case['plan'], $plan->canonicalArray());
        self::assertSame($case['expected_plan_hash'], (new PlanCodec())->hash($plan));
    }
}
