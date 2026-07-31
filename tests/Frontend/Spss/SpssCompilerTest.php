<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Frontend\Spss;

use OpenStatSpec\Frontend\Spss\SpssCompiler;
use OpenStatSpec\Frontend\Spss\SpssSyntaxException;
use OpenStatSpec\Transformation\Model\Action\CopySourceAction;
use OpenStatSpec\Transformation\Model\Action\SetMissingAction;
use OpenStatSpec\Transformation\Model\RecodeOperation;
use OpenStatSpec\Transformation\Model\Selector\ElseSelector;
use OpenStatSpec\Transformation\Model\Selector\MissingValueSelector;
use OpenStatSpec\Transformation\Model\SetValueLabelsOperation;
use OpenStatSpec\Transformation\Model\SetVariableLabelOperation;
use PHPUnit\Framework\TestCase;

final class SpssCompilerTest extends TestCase
{
    private const DATASET_ID = '018f47a2-4c10-7d34-8f11-93b1c3efc321';

    public function testCompilesSupportedSubsetIntoValidatedCanonicalPlan(): void
    {
        $plan = (new SpssCompiler())->compile(<<<'SPSS'
            RECODE score (1=10) (2 THRU 4=20) (SYSMIS=99) (ELSE=COPY).
            VARIABLE LABELS score 'Overall score'.
            VALUE LABELS score 10 'Low' 20 'High'.
            EXECUTE.
            SPSS, self::DATASET_ID);

        self::assertSame(self::DATASET_ID, $plan->datasetId());
        self::assertCount(3, $plan->operations());
        /** @var RecodeOperation $recode */
        $recode = $plan->operations()[0];
        self::assertInstanceOf(RecodeOperation::class, $recode);
        self::assertInstanceOf(MissingValueSelector::class, $recode->rules()[2]->selector());
        self::assertInstanceOf(ElseSelector::class, $recode->rules()[3]->selector());
        self::assertInstanceOf(CopySourceAction::class, $recode->rules()[3]->action());
        self::assertInstanceOf(SetVariableLabelOperation::class, $plan->operations()[1]);
        self::assertInstanceOf(SetValueLabelsOperation::class, $plan->operations()[2]);
        self::assertSame('openstatspec-transformation-plan-v1', $plan->canonicalArray()['contract']);
        self::assertSame('in_place', $plan->canonicalArray()['mode']);
    }

    public function testMakesSpssDefaultElseSemanticsExplicit(): void
    {
        $inPlace = (new SpssCompiler())->compile('RECODE score (1=2).', self::DATASET_ID);
        $into = (new SpssCompiler())->compile('RECODE score (1=2) INTO band.', self::DATASET_ID);
        /** @var RecodeOperation $inPlaceRecode */
        $inPlaceRecode = $inPlace->operations()[0];
        /** @var RecodeOperation $intoRecode */
        $intoRecode = $into->operations()[0];

        self::assertInstanceOf(CopySourceAction::class, $inPlaceRecode->rules()[1]->action());
        self::assertInstanceOf(SetMissingAction::class, $intoRecode->rules()[1]->action());
        self::assertInstanceOf(ElseSelector::class, $intoRecode->rules()[1]->selector());
    }

    public function testExpandsParallelSourceAndIntoLists(): void
    {
        $plan = (new SpssCompiler())->compile('RECODE first second (1=2) INTO new_first new_second.', self::DATASET_ID);

        self::assertCount(2, $plan->operations());
        self::assertSame('first', $plan->operations()[0]->sourceVariable());
        self::assertSame('new_first', $plan->operations()[0]->targetVariable());
        self::assertSame('second', $plan->operations()[1]->sourceVariable());
        self::assertSame('new_second', $plan->operations()[1]->targetVariable());
    }

    public function testPreservesSequentialOperationsOnTheSameTarget(): void
    {
        $plan = (new SpssCompiler())->compile(<<<'SPSS'
            RECODE score (1=2).
            RECODE score (2=3).
            VARIABLE LABELS score 'First label'.
            VARIABLE LABELS score 'Replacement label'.
            SPSS, self::DATASET_ID);

        self::assertCount(4, $plan->operations());
        self::assertSame(
            ['recode', 'recode', 'set_variable_label', 'set_variable_label'],
            array_map(static fn($operation): string => $operation->type(), $plan->operations()),
        );
    }

    public function testRejectsParallelRecodeThatWouldOverwriteALaterSource(): void
    {
        try {
            (new SpssCompiler())->compile(
                'RECODE a b (ELSE=COPY) INTO b c.',
                self::DATASET_ID,
            );
            self::fail('A dependency-unsafe parallel RECODE unexpectedly compiled.');
        } catch (SpssSyntaxException $exception) {
            self::assertStringContainsString(
                'overwrite a source used by a later pair',
                $exception->diagnostics[0]->message,
            );
        }
    }

    public function testRejectsDuplicateIntoTargetsWithinOneParallelRecode(): void
    {
        try {
            (new SpssCompiler())->compile(
                'RECODE first second (1=2) INTO result result.',
                self::DATASET_ID,
            );
            self::fail('A parallel RECODE with duplicate INTO targets unexpectedly compiled.');
        } catch (SpssSyntaxException $exception) {
            self::assertStringContainsString(
                'duplicate target variables',
                $exception->diagnostics[0]->message,
            );
        }
    }

    public function testBinderRejectsInvalidElsePositionAndIntoArity(): void
    {
        foreach ([
            'RECODE score (ELSE=COPY) (1=2).',
            'RECODE first second (1=2) INTO only_one.',
            "RECODE score ('a' THRU 'z'=1).",
        ] as $syntax) {
            $this->expectCompileFailure($syntax);
        }
    }

    public function testFailsClosedForUserMissingSelectorWithoutDatasetMetadata(): void
    {
        try {
            (new SpssCompiler())->compile('RECODE score (MISSING=0) (ELSE=COPY).', self::DATASET_ID);
            self::fail('MISSING unexpectedly compiled as system missing.');
        } catch (SpssSyntaxException $exception) {
            self::assertStringContainsString('user-missing', $exception->diagnostics[0]->message);
        }
    }

    public function testLargeIntegerLiteralIsParsedDirectlyAsBinary64(): void
    {
        $plan = (new SpssCompiler())->compile(
            'RECODE score (999999999999999999999=1) (ELSE=COPY).',
            self::DATASET_ID,
        );

        /** @var RecodeOperation $recode */
        $recode = $plan->operations()[0];
        /** @var \OpenStatSpec\Transformation\Model\Selector\ExactValueSelector $selector */
        $selector = $recode->rules()[0]->selector();
        self::assertInstanceOf(
            \OpenStatSpec\Transformation\Model\Selector\ExactValueSelector::class,
            $selector,
        );
        self::assertSame((float) '999999999999999999999', $selector->value()->numberValue());
        self::assertNotSame((float) PHP_INT_MAX, $selector->value()->numberValue());
    }

    public function testRejectsNonFiniteNumericLiteral(): void
    {
        $this->expectCompileFailure('RECODE score (1e999=1) (ELSE=COPY).');
    }

    private function expectCompileFailure(string $syntax): void
    {
        try {
            (new SpssCompiler())->compile($syntax, self::DATASET_ID);
            self::fail('Semantically invalid syntax unexpectedly compiled.');
        } catch (SpssSyntaxException $exception) {
            self::assertNotEmpty($exception->diagnostics);
        }
    }
}
