<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Support;

use OpenStatSpec\Spss\SpssEngine;

final class FakeSpssEngine implements SpssEngine
{
    /** @var list<array{targetPath: string, dataset: array<string, mixed>}> */
    private array $writes = [];

    /**
     * @param array{header: mixed, variables: array<int, mixed>, valueLabels: array<int, mixed>, documents: array<int, mixed>, info: array<int, mixed>, data: array<int, mixed>} $dataset
     */
    public function __construct(private array $dataset) {}

    public function read(string $sourcePath): array
    {
        return $this->dataset;
    }
    /** @param array<string, mixed> $dataset */
    public function write(string $targetPath, array $dataset): void
    {
        $this->writes[] = ['targetPath' => $targetPath, 'dataset' => $dataset];
    }

    /** @return array{targetPath: string, dataset: array<string, mixed>} */
    public function lastWrite(): array
    {
        $write = $this->writes[array_key_last($this->writes)] ?? null;
        if ($write === null) {
            throw new \LogicException('No SAV payload was written.');
        }

        return $write;
    }
}
