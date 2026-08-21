<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Release;

use PHPUnit\Framework\TestCase;

final class CiTransformationGateTest extends TestCase
{
    public function testMySqlOfficialTransformationGateHasIsolationCredentialsAndRejectsSkippedCases(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        self::assertIsString($workflow);
        $job = $this->job($workflow, 'mysql-integration');
        $jobEnvironment = $this->environment($job, 6);
        $serviceEnvironment = $this->environment($job, 10);

        self::assertArrayHasKey('OPENSTATSPEC_MYSQL_ADMIN_USER', $jobEnvironment);
        self::assertArrayHasKey('OPENSTATSPEC_MYSQL_ADMIN_PASSWORD', $jobEnvironment);
        self::assertSame('"%"', $serviceEnvironment['MYSQL_ROOT_HOST']);
        self::assertSame('root', $jobEnvironment['OPENSTATSPEC_MYSQL_ADMIN_USER']);
        self::assertSame(
            $serviceEnvironment['MYSQL_ROOT_PASSWORD'],
            $jobEnvironment['OPENSTATSPEC_MYSQL_ADMIN_PASSWORD'],
        );
        self::assertStringContainsString('OfficialInPlaceTransformation01Test', $job);
        self::assertStringContainsString(
            'vendor/bin/phpunit tests/Integration/OfficialInPlaceTransformation01Test.php '
            . '--filter \'/mysql/\' --fail-on-skipped',
            $job,
        );
    }

    private function job(string $workflow, string $name): string
    {
        $matched = preg_match(
            '/^  ' . preg_quote($name, '/') . ':\R(?<job>(?:^ {4,}.*(?:\R|\z))*)/m',
            $workflow,
            $matches,
        );
        self::assertSame(1, $matched, 'The required CI job is missing.');

        return $matches['job'];
    }

    /** @return array<string, string> */
    private function environment(string $job, int $indent): array
    {
        $matched = preg_match_all(
            '/^ {' . $indent . '}([A-Z][A-Z0-9_]+):\s*(\S.*?)\s*$/m',
            $job,
            $matches,
            PREG_SET_ORDER,
        );
        self::assertNotFalse($matched);

        $environment = [];
        foreach ($matches as $match) {
            $environment[$match[1]] = $match[2];
        }

        return $environment;
    }
}
