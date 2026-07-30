<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Support;

use OpenStatSpec\Spss\SpssEngine;
use SPSS\Sav\Dataset;
use Throwable;

final class FakeSpssEngine implements SpssEngine
{
    /** @var list<array{targetPath: string, dataset: Dataset}> */
    private array $writes = [];
    private ?string $lastReadPath = null;

    /** @param ?array<string, mixed> $identityOverride */
    public function __construct(
        private Dataset $dataset,
        private ?Throwable $readFailure = null,
        private ?array $identityOverride = null,
    ) {}

    public function identity(): array
    {
        if ($this->identityOverride !== null) {
            return $this->identityOverride;
        }

        return [
            'package' => 'fake-spss-engine',
            'version' => 'test',
            'active_version' => 'test',
            'claimed_version_range' => 'test-only',
            'ci_tested_versions' => ['test'],
            'claimed_supported' => true,
        ];
    }

    /** @return array<string, bool> */
    public function capabilities(): array
    {
        return array_fill_keys([
            'sav_read',
            'sav_write',
            'zsav_read',
            'zsav_write',
            'file_label',
            'documents',
            'source_encoding',
            'attributes',
            'variable_dictionary',
            'value_labels',
            'missing_rules',
            'lowest_highest_missing',
            'long_utf8_strings',
            'weight_variable',
            'variable_sets',
            'multiple_response_sets',
            'multiple_response_string_counted_value',
        ], true);
    }

    public function read(string $sourcePath): Dataset
    {
        $this->lastReadPath = $sourcePath;
        if ($this->readFailure !== null) {
            throw $this->readFailure;
        }

        return $this->dataset;
    }

    public function lastReadPath(): string
    {
        if ($this->lastReadPath === null) {
            throw new \LogicException('No SAV dataset was read.');
        }

        return $this->lastReadPath;
    }

    public function write(string $targetPath, Dataset $dataset): void
    {
        $this->writes[] = ['targetPath' => $targetPath, 'dataset' => $dataset];
    }

    /** @return array{targetPath: string, dataset: Dataset} */
    public function lastWrite(): array
    {
        $write = $this->writes[array_key_last($this->writes)] ?? null;
        if ($write === null) {
            throw new \LogicException('No SAV dataset was written.');
        }

        return $write;
    }
}
