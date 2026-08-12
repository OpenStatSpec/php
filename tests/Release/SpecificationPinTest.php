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
        $workflowFiles = glob(dirname(__DIR__, 2) . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE);
        self::assertNotFalse($workflowFiles);

        $refs = [];
        foreach ($workflowFiles as $workflowFile) {
            $workflow = file_get_contents($workflowFile);
            self::assertIsString($workflow);
            $lines = preg_split('/\R/', $workflow);
            self::assertIsArray($lines);

            foreach ($lines as $index => $line) {
                if (!preg_match('/^(\s*)repository:\s*OpenStatSpec\/specification\s*$/', $line, $repository)) {
                    continue;
                }

                $indent = strlen($repository[1]);
                $ref = null;
                for ($next = $index + 1; $next < count($lines); ++$next) {
                    if (trim($lines[$next]) === '') {
                        continue;
                    }
                    $nextIndent = strlen($lines[$next]) - strlen(ltrim($lines[$next]));
                    if ($nextIndent < $indent) {
                        break;
                    }
                    if (preg_match('/^\s*ref:\s*(\S+)\s*$/', $lines[$next], $match)) {
                        $ref = $match[1];
                        break;
                    }
                }

                self::assertNotNull($ref, "Missing immutable ref for {$workflowFile}");
                $refs[] = $ref;
            }
        }

        self::assertCount(5, $refs);
        self::assertSame(
            array_fill(0, count($refs), CapabilityDeclaration::SPECIFICATION_COMMIT),
            $refs,
        );
    }
}
