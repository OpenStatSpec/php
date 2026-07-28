<?php

declare(strict_types=1);

use OpenStatSpec\Spss\SpssAdapter;

const USAGE = <<<'TEXT'
Usage:
  php tools/memory-probe.php --source=/path/to/file.sav [options]

Options:
  --source=PATH       Existing user-supplied .sav or .zsav file (required).
  --database=PATH     New dedicated SQLite file (default: unique temp file).
  --keep-artifacts    Keep the SQLite and exported SPSS files after the probe.
  --help              Show this help.

The command prints a single JSON report to standard output. It performs a full
buffered import/export round trip; it does not claim streaming support.
TEXT;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Install Composer dependencies before running the memory probe.\n");
    exit(2);
}
require $autoload;

/** @return array<string, list<mixed>|string|false> */
function options(): array
{
    $parsed = getopt('', ['source:', 'database:', 'keep-artifacts', 'help']);
    return is_array($parsed) ? $parsed : [];
}

/** @return never */
function usageError(string $message): never
{
    fwrite(STDERR, $message . "\n\n" . USAGE . "\n");
    exit(2);
}

/** @return array{used_bytes: int, allocated_bytes: int, peak_used_bytes: int, peak_allocated_bytes: int} */
function memorySnapshot(): array
{
    return [
        'used_bytes' => memory_get_usage(false),
        'allocated_bytes' => memory_get_usage(true),
        'peak_used_bytes' => memory_get_peak_usage(false),
        'peak_allocated_bytes' => memory_get_peak_usage(true),
    ];
}

$options = options();
if (isset($options['help'])) {
    fwrite(STDOUT, USAGE . "\n");
    exit(0);
}

$sourceOption = $options['source'] ?? null;
if (!is_string($sourceOption) || $sourceOption === '') {
    usageError('--source is required.');
}
$source = realpath($sourceOption);
if (!is_string($source) || !is_file($source) || !is_readable($source)) {
    usageError('The source must be an existing readable file.');
}
$format = strtolower(pathinfo($source, PATHINFO_EXTENSION));
if (!in_array($format, ['sav', 'zsav'], true)) {
    usageError('The source extension must be .sav or .zsav.');
}
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    usageError('The pdo_sqlite extension is required for the isolated memory probe.');
}

$databaseOption = $options['database'] ?? null;
if ($databaseOption !== null && (!is_string($databaseOption) || $databaseOption === '')) {
    usageError('--database must name a new SQLite file.');
}
$token = bin2hex(random_bytes(8));
$database = is_string($databaseOption)
    ? $databaseOption
    : sys_get_temp_dir() . '/openstatspec-memory-probe-' . $token . '.sqlite';
if (file_exists($database)) {
    usageError('Refusing to use an existing database file: ' . $database);
}
$databaseDirectory = dirname($database);
if (!is_dir($databaseDirectory) || !is_writable($databaseDirectory)) {
    usageError('The database directory must exist and be writable: ' . $databaseDirectory);
}

$target = $database . '.roundtrip.' . $format;
if (file_exists($target)) {
    usageError('Refusing to overwrite an existing round-trip file: ' . $target);
}
$keepArtifacts = isset($options['keep-artifacts']);
$datasetName = 'memory_probe_' . $token;
$sourceBytes = filesize($source);
if (!is_int($sourceBytes)) {
    usageError('Could not determine the source file size.');
}

$baseline = memorySnapshot();
$report = null;
$exitCode = 0;

try {
    $pdo = new PDO('sqlite:' . $database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $adapter = new SpssAdapter($pdo);
    $capabilities = $adapter->capabilities();
    $import = $adapter->import($source, $datasetName);
    $afterImport = memorySnapshot();
    $export = $adapter->export($datasetName, $target);
    $final = memorySnapshot();

    $report = [
        'schema_version' => 1,
        'behavior' => [
            'streaming' => false,
            'mode' => 'fully_buffered_import_export_round_trip',
            'scope' => 'single_process_single_file',
        ],
        'runtime' => [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'engine' => $capabilities['engine'] ?? null,
        ],
        'source' => [
            'path' => $source,
            'format' => $format,
            'bytes' => $sourceBytes,
        ],
        'database' => [
            'driver' => 'sqlite',
            'isolation' => 'dedicated_file',
        ],
        'dataset' => [
            'name' => $datasetName,
            'case_count' => $import->caseCount,
        ],
        'measurements' => [
            'baseline' => $baseline,
            'after_import' => $afterImport,
            'final' => $final,
            'peak_allocated_delta_bytes' => max(0, $final['peak_allocated_bytes'] - $baseline['allocated_bytes']),
            'peak_used_delta_bytes' => max(0, $final['peak_used_bytes'] - $baseline['used_bytes']),
        ],
        'round_trip' => [
            'case_count' => $export->caseCount,
            'diagnostics' => count($import->diagnostics) + count($export->diagnostics),
        ],
        'artifacts' => [
            'retained' => $keepArtifacts,
            'database' => $keepArtifacts ? $database : null,
            'exported_file' => $keepArtifacts ? $target : null,
        ],
    ];
} catch (Throwable $exception) {
    $exitCode = 1;
    $report = [
        'schema_version' => 1,
        'behavior' => ['streaming' => false, 'mode' => 'fully_buffered_import_export_round_trip'],
        'error' => [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
        ],
        'measurements' => [
            'baseline' => $baseline,
            'failure' => memorySnapshot(),
        ],
    ];
} finally {
    if (!$keepArtifacts) {
        if (isset($pdo)) {
            $pdo = null;
        }
        if (is_file($target)) {
            @unlink($target);
        }
        if (is_file($database)) {
            @unlink($database);
        }
    }
}

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
fwrite(STDOUT, $json . "\n");
exit($exitCode);
