<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Execution;

use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Execution\DoltEvidence;
use OpenStatSpec\Transformation\Execution\DoltEvidenceReader;
use OpenStatSpec\Transformation\Execution\DoltGuard;
use OpenStatSpec\Transformation\Execution\InPlaceApplyRequest;
use OpenStatSpec\Transformation\Plan\Operation\ExecuteOperation;
use OpenStatSpec\Transformation\Plan\PlanContract;
use OpenStatSpec\Transformation\Plan\TransformationPlan;
use PHPUnit\Framework\TestCase;

final class DoltHeadGuardTest extends TestCase
{
    public function testItRejectsAHeadChangeWithTheOfficialPostMutationDiagnostic(): void
    {
        $reader = new class implements DoltEvidenceReader {
            private int $reads = 0;

            public function read(): DoltEvidence
            {
                return ++$this->reads === 1
                    ? new DoltEvidence('feature/recode', 'expected-head', [])
                    : new DoltEvidence('feature/recode', 'after456', ['respondents']);
            }
        };
        $guard = new DoltGuard($reader);
        $request = $this->request();
        $before = $guard->beforeExecution($request);

        try {
            $guard->afterExecution($request, $before);
            self::fail('A post-mutation Dolt HEAD change was accepted.');
        } catch (TransformationFailure $failure) {
            self::assertSame('dolt_context_changed', $failure->diagnosticCode());
        }
    }

    public function testItRejectsABranchChangeWithTheOfficialPostMutationDiagnostic(): void
    {
        $guard = new DoltGuard(new class implements DoltEvidenceReader {
            private int $reads = 0;

            public function read(): DoltEvidence
            {
                return ++$this->reads === 1
                    ? new DoltEvidence('feature/recode', 'expected-head', [])
                    : new DoltEvidence('main', 'expected-head', ['respondents']);
            }
        });
        $request = $this->request();
        $before = $guard->beforeExecution($request);

        try {
            $guard->afterExecution($request, $before);
            self::fail('A post-mutation Dolt branch change was accepted.');
        } catch (TransformationFailure $failure) {
            self::assertSame('dolt_context_changed', $failure->diagnosticCode());
        }
    }

    private function request(): InPlaceApplyRequest
    {
        return new InPlaceApplyRequest(
            new TransformationPlan(PlanContract::V02, 'parent', [new ExecuteOperation()]),
            'parent',
            '11111111-1111-4111-8111-111111111111',
            str_repeat('a', 64),
            'conformance-runner',
            'feature/recode',
            'expected-head',
        );
    }
}
