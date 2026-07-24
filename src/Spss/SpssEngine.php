<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

/** Internal boundary around the selected external SPSS reader/writer. */
interface SpssEngine
{
    /** @return array{header: mixed, variables: array<int, mixed>, valueLabels: array<int, mixed>, documents: array<int, mixed>, info: array<int, mixed>, data: array<int, mixed>} */
    public function read(string $sourcePath): array;

    /** @param array<string, mixed> $dataset */
    public function write(string $targetPath, array $dataset): void;
}
