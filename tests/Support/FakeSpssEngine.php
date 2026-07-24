<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Support;

use OpenStatSpec\Spss\SpssEngine;

final readonly class FakeSpssEngine implements SpssEngine
{
    /**
     * @param array{header: mixed, variables: array<int, mixed>, valueLabels: array<int, mixed>, documents: array<int, mixed>, info: array<int, mixed>, data: array<int, mixed>} $dataset
     */
    public function __construct(private array $dataset) {}

    public function read(string $sourcePath): array
    {
        return $this->dataset;
    }
    /** @param array<string, mixed> $dataset */
    public function write(string $targetPath, array $dataset): void {}
}
