<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Release;

use OpenStatSpec\Core\CapabilityDeclaration;
use PHPUnit\Framework\TestCase;

final class SpecificationPinTest extends TestCase
{
    public function testEverySpecificationCheckoutUsesTheStableReleaseCommit(): void
    {
        self::assertSame(
            'cd8f198c68b849eb8ed018a894670a0904c2181d',
            CapabilityDeclaration::SPECIFICATION_COMMIT,
        );
        $workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        self::assertIsString($workflow);

        preg_match_all(
            '/repository:\s*OpenStatSpec\/specification\s*\n\s*ref:\s*([0-9a-f]{40})/',
            $workflow,
            $matches,
        );

        self::assertSame(
            array_fill(0, count($matches[1]), CapabilityDeclaration::SPECIFICATION_COMMIT),
            $matches[1],
        );
        self::assertCount(5, $matches[1]);
    }
}
