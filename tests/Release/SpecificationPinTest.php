<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Release;

use OpenStatSpec\Core\CapabilityDeclaration;
use OpenStatSpec\Tests\Support\SpecificationManifest;
use PHPUnit\Framework\TestCase;

final class SpecificationPinTest extends TestCase
{
    public function testEverySpecificationCheckoutUsesTheReleasedSpecificationCommit(): void
    {
        self::assertSame('v0.5.0', CapabilityDeclaration::SPECIFICATION_RELEASE);
        self::assertSame(
            '864e84479f554b8ee250ffed44c4dfb963750d4a',
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

        foreach ([
            'transformation/plan-0.1.schema.json',
            'transformation/plan-0.2.schema.json',
            'transformation/spss-syntax-frontend-0.2.schema.json',
            'conformance/transformation-plan-0.1.json',
            'conformance/transformation-plan-0.2.json',
            'conformance/spss-syntax-frontend-0.2.json',
            'conformance/in-place-transformation-0.1.json',
            'conformance/in-place-transformation-0.2.json',
        ] as $relative) {
            self::assertFileExists(SpecificationManifest::path($relative));
        }
    }
}
