<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Canonical;

use OpenStatSpec\Transformation\Model\Action\AssignValueAction;
use OpenStatSpec\Transformation\Model\Action\CopySourceAction;
use OpenStatSpec\Transformation\Model\Action\SetMissingAction;
use OpenStatSpec\Transformation\Model\RecodeOperation;
use OpenStatSpec\Transformation\Model\RecodeRule;
use OpenStatSpec\Transformation\Model\ScalarValue;
use OpenStatSpec\Transformation\Model\Selector\ElseSelector;
use OpenStatSpec\Transformation\Model\Selector\ExactValueSelector;
use OpenStatSpec\Transformation\Model\Selector\MissingValueSelector;
use OpenStatSpec\Transformation\Model\Selector\NumericRangeSelector;
use OpenStatSpec\Transformation\Model\SetValueLabelsOperation;
use OpenStatSpec\Transformation\Model\SetVariableLabelOperation;
use OpenStatSpec\Transformation\Model\TransformationPlan;
use OpenStatSpec\Transformation\Model\ValueLabel;
use OpenStatSpec\Transformation\Validation\InvalidTransformationPlan;
use OpenStatSpec\Transformation\Validation\PlanValidator;
use PHPUnit\Framework\TestCase;

final class TransformationPlanTest extends TestCase
{
    private const DATASET_ID = '123e4567-e89b-42d3-a456-426614174000';

    public function testCanonicalPlanIsTypedSourceNeutralAndDeterministic(): void
    {
        $plan = $this->validPlan();
        (new PlanValidator())->assertValid($plan);

        self::assertSame(self::DATASET_ID, $plan->datasetId());
        self::assertCount(3, $plan->operations());
        self::assertSame('age', $plan->operations()[0]->sourceVariable());
        self::assertSame('age_group', $plan->operations()[0]->targetVariable());
        self::assertSame(TransformationPlan::CONTRACT, $plan->canonicalArray()['contract']);
        self::assertSame('in_place', $plan->canonicalArray()['mode']);
        self::assertArrayNotHasKey('output_dataset_id', $plan->canonicalArray());
        self::assertArrayNotHasKey('output_table', $plan->canonicalArray());
        self::assertSame($plan->canonicalJson(), $plan->canonicalJson());
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $plan->hash());
        self::assertSame(hash('sha256', $plan->canonicalJson()), $plan->hash());
        self::assertStringNotContainsString('spss', strtolower($plan->canonicalJson()));
        self::assertStringNotContainsString('stata', strtolower($plan->canonicalJson()));
        self::assertStringNotContainsString('sas', strtolower($plan->canonicalJson()));
    }

    public function testCanonicalJsonHasStableObjectKeyOrderingAndTypedNumbers(): void
    {
        $plan = new TransformationPlan(self::DATASET_ID, [
            new RecodeOperation('score', 'score', [
                new RecodeRule(
                    new ExactValueSelector(ScalarValue::number(1)),
                    new AssignValueAction(ScalarValue::number(-0.0)),
                ),
                new RecodeRule(new ElseSelector(), new CopySourceAction()),
            ]),
        ]);

        self::assertSame(
            '{"contract":"openstatspec-transformation-plan-v1","dataset_id":"123e4567-e89b-42d3-a456-426614174000","mode":"in_place","operations":[{"rules":[{"action":{"type":"assign","value":{"binary64":"0000000000000000","type":"number"}},"selector":{"type":"exact","value":{"binary64":"3ff0000000000000","type":"number"}}},{"action":{"type":"copy_source"},"selector":{"type":"else"}}],"source_variable":"score","target_variable":"score","type":"recode"}]}',
            $plan->canonicalJson(),
        );
        self::assertSame('d562adfb994ddad015bd0fee06dc56026c6fa405476ca980e92190c00162ba52', $plan->hash());
    }

    public function testValidatorCollectsIdentityNameAndLabelViolations(): void
    {
        $duplicateLabels = [
            new ValueLabel(ScalarValue::number(1), 'One'),
            new ValueLabel(ScalarValue::number(1.0), 'Still one'),
        ];
        $plan = new TransformationPlan('NOT-A-UUID', [
            new SetVariableLabelOperation('1 invalid', "bad\0label"),
            new SetVariableLabelOperation('1 invalid', 'again'),
            new SetValueLabelsOperation('status', $duplicateLabels),
        ]);

        $result = (new PlanValidator())->validate($plan);

        self::assertFalse($result->isValid());
        self::assertSame([
            'dataset_id.invalid_uuid',
            'variable.invalid_name',
            'variable.invalid_name',
            'text.invalid_unicode',
            'variable.invalid_name',
            'variable.invalid_name',
            'value_labels.duplicate_value',
        ], $this->codes($result->violations()));

        $this->expectException(InvalidTransformationPlan::class);
        $result->throwIfInvalid();
    }

    public function testValidatorAllowsSequentialOperationsOnTheSameTarget(): void
    {
        $plan = new TransformationPlan(self::DATASET_ID, [
            new SetVariableLabelOperation('status', 'First label'),
            new SetVariableLabelOperation('status', 'Replacement label'),
            new RecodeOperation('status', 'status', [
                new RecodeRule(new ElseSelector(), new CopySourceAction()),
            ]),
            new RecodeOperation('status', 'status', [
                new RecodeRule(new ElseSelector(), new AssignValueAction(ScalarValue::number(1))),
            ]),
        ]);

        self::assertTrue((new PlanValidator())->validate($plan)->isValid());
    }

    public function testValidatorRejectsAmbiguousAndIncompleteRecodeMappings(): void
    {
        $plan = new TransformationPlan(self::DATASET_ID, [
            new RecodeOperation('score', 'score', [
                new RecodeRule(new ExactValueSelector(ScalarValue::number(2)), new SetMissingAction()),
                new RecodeRule(new ExactValueSelector(ScalarValue::number(2.0)), new CopySourceAction()),
                new RecodeRule(new NumericRangeSelector(0.0, 10.0), new CopySourceAction()),
                new RecodeRule(new NumericRangeSelector(10.0, null), new CopySourceAction()),
                new RecodeRule(new MissingValueSelector(), new SetMissingAction()),
                new RecodeRule(new MissingValueSelector(), new CopySourceAction()),
            ]),
        ]);

        $codes = $this->codes((new PlanValidator())->validate($plan)->violations());

        self::assertContains('recode.else_required_once', $codes);
        self::assertContains('recode.ambiguous_mapping', $codes);
        self::assertGreaterThanOrEqual(4, count(array_filter(
            $codes,
            static fn(string $code): bool => $code === 'recode.ambiguous_mapping',
        )));
    }

    public function testValidatorRejectsMalformedRangesElseOrderingAndNonFiniteValues(): void
    {
        $plan = new TransformationPlan(self::DATASET_ID, [
            new RecodeOperation('score', 'score', [
                new RecodeRule(new NumericRangeSelector(null, null), new CopySourceAction()),
                new RecodeRule(new NumericRangeSelector(5.0, 4.0), new CopySourceAction()),
                new RecodeRule(new NumericRangeSelector(INF, null), new CopySourceAction()),
                new RecodeRule(new ElseSelector(), new AssignValueAction(ScalarValue::number(NAN))),
                new RecodeRule(new ExactValueSelector(ScalarValue::string('late')), new CopySourceAction()),
            ]),
        ]);

        $codes = $this->codes((new PlanValidator())->validate($plan)->violations());

        self::assertContains('selector.range_unbounded', $codes);
        self::assertContains('selector.range_reversed', $codes);
        self::assertContains('selector.range_non_finite', $codes);
        self::assertContains('value.non_finite', $codes);
        self::assertContains('recode.else_not_last', $codes);
    }

    public function testEmptyPlanAndEmptyRecodeAreRejected(): void
    {
        $empty = (new PlanValidator())->validate(new TransformationPlan(self::DATASET_ID, []));
        self::assertSame(['operations.empty'], $this->codes($empty->violations()));

        $emptyRecode = (new PlanValidator())->validate(new TransformationPlan(self::DATASET_ID, [
            new RecodeOperation('score', 'score', []),
        ]));
        self::assertSame(['recode.rules_empty'], $this->codes($emptyRecode->violations()));
    }

    private function validPlan(): TransformationPlan
    {
        return new TransformationPlan(self::DATASET_ID, [
            new RecodeOperation('age', 'age_group', [
                new RecodeRule(new NumericRangeSelector(null, 17.0), new AssignValueAction(ScalarValue::number(1))),
                new RecodeRule(new NumericRangeSelector(18.0, null), new AssignValueAction(ScalarValue::number(2))),
                new RecodeRule(new MissingValueSelector(), new SetMissingAction()),
                new RecodeRule(new ElseSelector(), new CopySourceAction()),
            ]),
            new SetVariableLabelOperation('age_group', 'Age group'),
            new SetValueLabelsOperation('age_group', [
                new ValueLabel(ScalarValue::number(1), 'Child'),
                new ValueLabel(ScalarValue::number(2), 'Adult'),
            ]),
        ]);
    }

    /**
     * @param list<\OpenStatSpec\Transformation\Validation\ValidationViolation> $violations
     * @return list<string>
     */
    private function codes(array $violations): array
    {
        return array_map(
            static fn(\OpenStatSpec\Transformation\Validation\ValidationViolation $violation): string => $violation->code,
            $violations,
        );
    }
}
