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
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidPlans(): iterable
    {
        yield 'missing root member' => [[
            'contract' => 'openstatspec-transformation-plan-v0.1',
            'operations' => [['op' => 'set_variable_label', 'variable' => 'q1', 'label' => 'Question']],
        ], 'plan_schema_invalid'];
        yield 'extra operation member' => [[
            'contract' => 'openstatspec-transformation-plan-v0.1',
            'input_alias' => 'parent',
            'operations' => [['op' => 'set_variable_label', 'variable' => 'q1', 'label' => 'Question', 'extra' => true]],
        ], 'plan_schema_invalid'];
        yield 'empty input alias' => [[
            'contract' => 'openstatspec-transformation-plan-v0.1',
            'input_alias' => '',
            'operations' => [['op' => 'set_variable_label', 'variable' => 'q1', 'label' => 'Question']],
        ], 'plan_schema_invalid'];
        yield 'empty operations' => [[
            'contract' => 'openstatspec-transformation-plan-v0.1',
            'input_alias' => 'parent',
            'operations' => [],
        ], 'plan_schema_invalid'];
        yield 'unknown operation' => [[
            'contract' => 'openstatspec-transformation-plan-v0.1',
            'input_alias' => 'parent',
            'operations' => [['op' => 'delete_variable']],
        ], 'plan_schema_invalid'];
        yield 'malformed binary64 bits' => [[
            'contract' => 'openstatspec-transformation-plan-v0.1',
            'input_alias' => 'parent',
            'operations' => [[
                'op' => 'replace_value_labels',
                'variable' => 'q1',
                'labels' => [['value' => ['type' => 'binary64', 'bits' => 'not-binary64'], 'label' => 'One']],
            ]],
        ], 'plan_schema_invalid'];
        yield 'non-finite binary64 bits' => [[
            'contract' => 'openstatspec-transformation-plan-v0.1',
            'input_alias' => 'parent',
            'operations' => [[
                'op' => 'replace_value_labels',
                'variable' => 'q1',
                'labels' => [['value' => ['type' => 'binary64', 'bits' => '7ff0000000000000'], 'label' => 'Infinity']],
            ]],
        ], 'plan_schema_invalid'];
        yield 'negative zero binary64 bits' => [[
            'contract' => 'openstatspec-transformation-plan-v0.1',
            'input_alias' => 'parent',
            'operations' => [[
                'op' => 'replace_value_labels',
                'variable' => 'q1',
                'labels' => [['value' => ['type' => 'binary64', 'bits' => '8000000000000000'], 'label' => 'Zero']],
            ]],
        ], 'plan_schema_invalid'];
        yield 'descending range' => [[
            'contract' => 'openstatspec-transformation-plan-v0.1',
            'input_alias' => 'parent',
            'operations' => [[
                'op' => 'recode',
                'source' => 'q1',
                'target' => 'q1',
                'target_mode' => 'replace',
                'rules' => [[
                    'match' => [
                        'kind' => 'range',
                        'lower' => ['type' => 'binary64', 'bits' => '4000000000000000'],
                        'upper' => ['type' => 'binary64', 'bits' => '3ff0000000000000'],
                    ],
                    'result' => ['kind' => 'copy'],
                ]],
                'unmatched' => ['kind' => 'copy'],
            ]],
        ], 'invalid_numeric_range'];
        yield 'reserved recode target' => [[
            'contract' => 'openstatspec-transformation-plan-v0.1',
            'input_alias' => 'parent',
            'operations' => [[
                'op' => 'recode',
                'source' => 'source',
                'target' => '__hidden',
                'target_mode' => 'replace',
                'rules' => [[
                    'match' => ['kind' => 'values', 'values' => [['type' => 'binary64', 'bits' => '3ff0000000000000']]],
                    'result' => ['kind' => 'copy'],
                ]],
                'unmatched' => ['kind' => 'copy'],
            ]],
        ], 'reserved_target_name'];
        yield 'duplicate exact typed label values' => [[
            'contract' => 'openstatspec-transformation-plan-v0.1',
            'input_alias' => 'parent',
            'operations' => [[
                'op' => 'replace_value_labels',
                'variable' => 'q1',
                'labels' => [
                    ['value' => ['type' => 'string', 'value' => 'A'], 'label' => 'Alpha'],
                    ['value' => ['type' => 'string', 'value' => 'A'], 'label' => 'Again'],
                ],
            ]],
        ], 'duplicate_value_label'];
    }

    /** @param array<string, mixed> $plan */
    #[DataProvider('invalidPlans')]
    public function testRejectsInvalidPlanWithStableDiagnostic(array $plan, string $expectedCode): void
    {
        try {
            (new PlanCodec())->fromArray($plan);
            self::fail('Expected ' . $expectedCode . '.');
        } catch (TransformationFailure $failure) {
            self::assertSame($expectedCode, $failure->diagnosticCode());
        }
    }

    public function testRejectsInvalidUtf8AsPlanSchemaInvalid(): void
    {
        $plan = [
            'contract' => 'openstatspec-transformation-plan-v0.1',
            'input_alias' => "invalid-\xB1",
            'operations' => [[
                'op' => 'set_variable_label',
                'variable' => 'q1',
                'label' => 'Question',
            ]],
        ];

        try {
            (new PlanCodec())->fromArray($plan);
            self::fail('Expected plan_schema_invalid for malformed UTF-8.');
        } catch (TransformationFailure $failure) {
            self::assertSame('plan_schema_invalid', $failure->diagnosticCode());
        }
    }

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
