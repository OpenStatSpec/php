<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Support;

use OpenStatSpec\Spss\SpssEngine;
use SPSS\Sav\Dataset;

final class FakeSpssEngine implements SpssEngine
{
    /** @var list<array{targetPath: string, dataset: Dataset}> */
    private array $writes = [];

    public function __construct(private Dataset $dataset) {}

    public function identity(): array
    {
        return ["package" => "fake-spss-engine", "version" => "test"];
    }

    public function read(string $sourcePath): Dataset
    {
        return $this->dataset;
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
