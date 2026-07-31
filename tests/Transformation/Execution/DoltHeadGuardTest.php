<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Execution;

use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Transformation\Execution\DoltEvidence;
use OpenStatSpec\Transformation\Execution\DoltEvidenceReader;
use OpenStatSpec\Transformation\Execution\DoltGuard;
use PHPUnit\Framework\TestCase;

final class DoltHeadGuardTest extends TestCase
{
    public function testItRejectsAHeadChangeWithoutMutatingRepositoryState(): void
    {
        $reader = new class implements DoltEvidenceReader {
            private int $reads = 0;

            public function read(): DoltEvidence
            {
                return ++$this->reads === 1
                    ? new DoltEvidence('main', 'before123', [])
                    : new DoltEvidence('main', 'after456', ['respondents']);
            }
        };
        $guard = new DoltGuard($reader);
        $before = $guard->beforeExecution();

        $this->expectException(UnsupportedOperation::class);
        $this->expectExceptionMessage('HEAD changed');
        $guard->afterExecution($before);
    }
}
