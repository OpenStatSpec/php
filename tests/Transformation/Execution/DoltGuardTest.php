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

final class DoltGuardTest extends TestCase
{
    public function testItRequiresCallerSuppliedDoltContextBeforeReadingRepositoryState(): void
    {
        $reader = new class implements DoltEvidenceReader {
            public int $reads = 0;

            public function read(): DoltEvidence
            {
                ++$this->reads;
                return new DoltEvidence('feature/recode', 'expected-head', []);
            }
        };
        $guard = new DoltGuard($reader);

        try {
            $guard->beforeExecution($this->request());
            self::fail('A Dolt apply without expected branch and HEAD was accepted.');
        } catch (TransformationFailure $failure) {
            self::assertSame('dolt_context_required', $failure->diagnosticCode());
            self::assertSame(0, $reader->reads);
        }
    }

    public function testItRejectsAnUnexpectedBranchBeforeExecution(): void
    {
        $guard = new DoltGuard($this->reader(
            new DoltEvidence('main', 'expected-head', []),
        ));

        try {
            $guard->beforeExecution($this->request('feature/recode', 'expected-head'));
            self::fail('An unexpected Dolt branch was accepted.');
        } catch (TransformationFailure $failure) {
            self::assertSame('dolt_branch_mismatch', $failure->diagnosticCode());
        }
    }

    public function testItRejectsAnUnexpectedHeadBeforeExecution(): void
    {
        $guard = new DoltGuard($this->reader(
            new DoltEvidence('feature/recode', 'other-head', []),
        ));

        try {
            $guard->beforeExecution($this->request('feature/recode', 'expected-head'));
            self::fail('An unexpected Dolt HEAD was accepted.');
        } catch (TransformationFailure $failure) {
            self::assertSame('dolt_head_mismatch', $failure->diagnosticCode());
        }
    }

    public function testItRejectsADirtyWorkingSetBeforeExecution(): void
    {
        $guard = new DoltGuard($this->reader(
            new DoltEvidence('main', 'abc123', ['respondents']),
        ));

        try {
            $guard->beforeExecution($this->request('main', 'abc123'));
            self::fail('A dirty Dolt working set was accepted.');
        } catch (TransformationFailure $failure) {
            self::assertSame('dolt_working_set_dirty', $failure->diagnosticCode());
            self::assertStringContainsString('respondents', $failure->getMessage());
        }
    }

    public function testItCapturesPostEvidenceWithoutRequiringTheExpectedEditToBeClean(): void
    {
        $guard = new DoltGuard($this->reader(
            new DoltEvidence('main', 'abc123', []),
            new DoltEvidence('main', 'abc123', ['respondents', 'variable']),
        ));

        $request = $this->request('main', 'abc123');
        $before = $guard->beforeExecution($request);
        $after = $guard->afterExecution($request, $before);

        self::assertTrue($before->isClean());
        self::assertFalse($after->isClean());
        self::assertSame(['respondents', 'variable'], $after->dirtyTables());
    }

    private function reader(DoltEvidence ...$evidence): DoltEvidenceReader
    {
        return new class (array_values($evidence)) implements DoltEvidenceReader {
            /** @param list<DoltEvidence> $evidence */
            public function __construct(private array $evidence) {}

            public function read(): DoltEvidence
            {
                $next = array_shift($this->evidence);
                if ($next === null) {
                    throw new \LogicException('The test evidence queue is empty.');
                }

                return $next;
            }
        };
    }

    private function request(?string $branch = null, ?string $head = null): InPlaceApplyRequest
    {
        return new InPlaceApplyRequest(
            new TransformationPlan(PlanContract::V02, 'parent', [new ExecuteOperation()]),
            'parent',
            '11111111-1111-4111-8111-111111111111',
            str_repeat('a', 64),
            'conformance-runner',
            $branch,
            $head,
        );
    }
}
