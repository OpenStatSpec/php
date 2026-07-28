<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Tool;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MemoryProbeSmokeTest extends TestCase
{
    public function testProbeReportsNonStreamingJsonWithoutMemoryThreshold(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for the isolated memory-probe smoke test.');
        }
        $fixture = $this->fixture();
        $database = sys_get_temp_dir() . '/openstatspec-memory-probe-smoke-' . bin2hex(random_bytes(8)) . '.sqlite';
        $root = dirname(__DIR__, 2);
        $command = [PHP_BINARY, $root . '/tools/memory-probe.php', '--source=' . $fixture, '--database=' . $database];
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        self::assertSame(0, $status, is_string($stderr) ? $stderr : 'memory probe failed');
        self::assertIsString($stdout);
        $report = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame(1, $report['schema_version']);
        self::assertFalse($report['behavior']['streaming']);
        self::assertSame('fully_buffered_import_export_round_trip', $report['behavior']['mode']);
        self::assertSame('sqlite', $report['database']['driver']);
        self::assertSame('dedicated_file', $report['database']['isolation']);
        self::assertGreaterThan(0, $report['source']['bytes']);
        self::assertGreaterThan(0, $report['dataset']['case_count']);
        self::assertSame($report['dataset']['case_count'], $report['round_trip']['case_count']);
        self::assertGreaterThanOrEqual(
            $report['measurements']['baseline']['allocated_bytes'],
            $report['measurements']['final']['peak_allocated_bytes'],
        );
        self::assertFalse($report['artifacts']['retained']);
        self::assertNull($report['artifacts']['database']);
        self::assertNull($report['artifacts']['exported_file']);
        self::assertFileDoesNotExist($database);
        self::assertFileDoesNotExist($database . '.roundtrip.sav');
    }

    private function fixture(): string
    {
        $configured = getenv('OPENSTATSPEC_SPECIFICATION_DIR');
        $candidates = [];
        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured;
        }
        $candidates[] = dirname(__DIR__, 3) . '/specification';
        foreach ($candidates as $root) {
            $fixture = realpath($root . '/conformance/fixtures/core-numeric-string.sav');
            if (is_string($fixture) && is_file($fixture)) {
                return $fixture;
            }
        }
        throw new RuntimeException('The official core-numeric-string.sav fixture is required for the memory-probe smoke test.');
    }
}
