<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Execution;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Transformation\Execution\DoltEvidence;
use OpenStatSpec\Transformation\Execution\DoltEvidenceReader;
use OpenStatSpec\Transformation\Execution\DoltGuard;
use PHPUnit\Framework\TestCase;

final class DoltGuardTest extends TestCase
{
    public function testItRejectsADirtyWorkingSetBeforeExecution(): void
    {
        $guard = new DoltGuard($this->reader(
            new DoltEvidence('main', 'abc123', ['respondents']),
        ));

        try {
            $guard->beforeExecution();
            self::fail('A dirty Dolt working set was accepted.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::SqlProfileOperationUnavailable, $exception->diagnosticCode);
            self::assertStringContainsString('clean working set', $exception->getMessage());
            self::assertStringContainsString('respondents', $exception->getMessage());
        }
    }

    public function testItCapturesPostEvidenceWithoutRequiringTheExpectedEditToBeClean(): void
    {
        $guard = new DoltGuard($this->reader(
            new DoltEvidence('main', 'abc123', []),
            new DoltEvidence('main', 'abc123', ['respondents', 'variable']),
        ));

        $before = $guard->beforeExecution();
        $after = $guard->afterExecution($before);

        self::assertTrue($before->isClean());
        self::assertFalse($after->isClean());
        self::assertSame(['respondents', 'variable'], $after->dirtyTables());
    }

    public function testItRejectsBranchOrHeadMutation(): void
    {
        $guard = new DoltGuard($this->reader(
            new DoltEvidence('main', 'abc123', []),
            new DoltEvidence('other', 'abc123', ['respondents']),
        ));
        $before = $guard->beforeExecution();

        $this->expectException(UnsupportedOperation::class);
        $this->expectExceptionMessage('branch changed');
        $guard->afterExecution($before);
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
}
