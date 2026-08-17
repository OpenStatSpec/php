<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Conformance;

use OpenStatSpec\Tests\Support\SpecificationManifest;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Plan\PlanCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransformationPlan02Test extends TestCase
{
    public function testRejectsV02OperationUnderV01Contract(): void
    {
        $plan = [
            'contract' => 'openstatspec-transformation-plan-v0.1',
            'input_alias' => 'parent',
            'operations' => [[
                'op' => 'assign',
                'target' => 'target',
                'target_mode' => 'replace',
                'value' => ['kind' => 'variable', 'variable' => 'source'],
            ]],
        ];

        try {
            (new PlanCodec())->fromArray($plan);
            self::fail('Expected plan_schema_invalid.');
        } catch (TransformationFailure $failure) {
            self::assertSame('plan_schema_invalid', $failure->diagnosticCode());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function cases(): iterable
    {
        foreach (SpecificationManifest::load('conformance/transformation-plan-0.2.json')['cases'] as $case) {
            yield $case['id'] => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testOfficialPlan02(array $case): void
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

        $codec = new PlanCodec();
        $plan = $codec->fromArray($case['plan']);
        self::assertSame($case['plan'], $plan->canonicalArray());
        self::assertSame($case['expected_plan_hash'], $codec->hash($plan));
    }
}
