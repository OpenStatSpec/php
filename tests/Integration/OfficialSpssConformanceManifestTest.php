<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

use OpenStatSpec\Spss\PhpSpssEngine;
use OpenStatSpec\Spss\SpssAdapter;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OfficialSpssConformanceManifestTest extends TestCase
{
    public function testOfficialSavAndZsavFixturesRoundTripThroughEveryAvailableProfile(): void
    {
        $specification = $this->specificationRoot();
        $manifestPath = $specification . '/conformance/spss-sav-zsav-1.0.json';
        self::assertFileExists($manifestPath);
        $manifestContents = file_get_contents($manifestPath);
        if (!is_string($manifestContents)) {
            throw new RuntimeException('Could not read the official conformance manifest.');
        }
        $manifest = json_decode($manifestContents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame('1.0', $manifest['manifest_version'] ?? null);
        self::assertIsArray($manifest['fixtures'] ?? null);

        $engine = new PhpSpssEngine();
        $preflightSeen = false;
        foreach ($this->profiles() as $profile => $pdo) {
            $executed = [];
            foreach ($manifest['fixtures'] as $fixture) {
                self::assertIsArray($fixture);
                $id = $fixture['id'] ?? null;
                $source = $fixture['source'] ?? null;
                self::assertIsString($id);
                self::assertIsString($source);
                if ($id === 'preflight-failure') {
                    $preflightSeen = true;
                    continue;
                }

                $sourcePath = $specification . '/conformance/' . $source;
                self::assertFileExists($sourcePath, 'Missing official fixture: ' . $id);
                $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
                self::assertContains($extension, ['sav', 'zsav']);
                $token = bin2hex(random_bytes(8));
                $targetPath = sys_get_temp_dir() . '/openstatspec-official-' . $token . '.' . $extension;

                try {
                    $sourceDataset = $engine->read($sourcePath);
                    $adapter = new SpssAdapter($pdo, $engine);
                    $datasetName = 'official_' . $profile . '_' . str_replace('-', '_', $id) . '_' . $token;
                    $import = $adapter->import($sourcePath, $datasetName);
                    self::assertSame([], $import->diagnostics);
                    $export = $adapter->export($import->datasetName, $targetPath);
                    self::assertSame([], $export->diagnostics);

                    $roundTrip = $engine->read($targetPath);
                    $context = $profile . '/' . $id;
                    self::assertEquals($sourceDataset->rows(), $roundTrip->rows(), $context . ': cases or case order');
                    self::assertEquals($this->normativeVariables($sourceDataset->variables()), $this->normativeVariables($roundTrip->variables()), $context . ': variable dictionary');
                    self::assertEquals($this->normativeMetadata($sourceDataset->metadata), $this->normativeMetadata($roundTrip->metadata), $context . ': file metadata');
                    self::assertSame($sourceDataset->technicalMetadata->sourceFormat, $roundTrip->technicalMetadata->sourceFormat, $context . ': source format');
                    self::assertSame($sourceDataset->technicalMetadata->encoding, $roundTrip->technicalMetadata->encoding, $context . ': source encoding');
                    $executed[] = $id;
                } finally {
                    @unlink($targetPath);
                }
            }

            self::assertSame([
                'core-numeric-string',
                'dictionary-and-display',
                'missing-rules',
                'long-utf8-and-attributes',
                'sets',
                'zsav-compressed',
            ], $executed, $profile . ': official conformance fixture coverage');
        }

        self::assertTrue($preflightSeen, 'The profile-specific preflight fixture declaration is required.');
    }

    /** @return array<string, PDO> */
    private function profiles(): array
    {
        $profiles = [];
        if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $profiles['sqlite'] = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        foreach ([
            'mysql' => 'OPENSTATSPEC_MYSQL',
            'mariadb' => 'OPENSTATSPEC_MARIADB',
            'postgresql' => 'OPENSTATSPEC_PG',
        ] as $name => $prefix) {
            $dsn = getenv($prefix . '_DSN');
            $driver = $name === 'postgresql' ? 'pgsql' : 'mysql';
            if (!is_string($dsn) || $dsn === '' || !in_array($driver, PDO::getAvailableDrivers(), true)) {
                continue;
            }
            $user = getenv($prefix . '_USER');
            $password = getenv($prefix . '_PASSWORD');
            $profiles[$name] = new PDO($dsn, is_string($user) ? $user : null, is_string($password) ? $password : null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        if ($profiles === []) {
            self::markTestSkipped('No supported PDO profile is available.');
        }

        return $profiles;
    }

    /**
     * SPSS compatibility short names and binary dictionary indexes are writer
     * layout details, not normative OpenStatSpec variable semantics.
     *
     * @param list<\SPSS\Sav\VariableMetadata> $variables
     * @return list<array<string, mixed>>
     */
    private function normativeVariables(array $variables): array
    {
        return array_map(static fn(\SPSS\Sav\VariableMetadata $variable): array => [
            'name' => $variable->name,
            'type' => $variable->type,
            'width' => $variable->width,
            'printFormat' => $variable->printFormat,
            'writeFormat' => $variable->writeFormat,
            'label' => $variable->label,
            'valueLabels' => $variable->valueLabels->labels(),
            'missingValues' => $variable->missingValues,
            'measure' => $variable->measure,
            'alignment' => $variable->alignment,
            'columns' => $variable->columns,
            'role' => $variable->role,
            'attributes' => $variable->attributes(),
        ], $variables);
    }

    /**
     * File creation timestamps are writer-specific and explicitly excluded
     * from semantic round-trip comparison by the profile.
     *
     * @return array<string, mixed>
     */
    private function normativeMetadata(\SPSS\Sav\FileMetadata $metadata): array
    {
        return [
            'label' => $metadata->label,
            'weightVariableName' => $metadata->weightVariableName,
            'documents' => $metadata->documents(),
            'attributes' => $metadata->attributes(),
            'variableSets' => $metadata->variableSets(),
            'multipleResponseSets' => $metadata->multipleResponseSets(),
        ];
    }

    private function specificationRoot(): string
    {
        $configured = getenv('OPENSTATSPEC_SPECIFICATION_DIR');
        $candidates = [];
        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured;
        }
        $candidates[] = dirname(__DIR__, 2) . '/openstatspec-specification';
        $candidates[] = dirname(__DIR__, 3) . '/specification';

        foreach ($candidates as $candidate) {
            $root = realpath($candidate);
            if (is_string($root) && is_file($root . '/conformance/spss-sav-zsav-1.0.json')) {
                return $root;
            }
        }

        throw new RuntimeException(
            'The official OpenStatSpec specification checkout is required. '
            . 'Set OPENSTATSPEC_SPECIFICATION_DIR to its root.',
        );
    }
}
