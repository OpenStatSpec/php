<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use SPSS\Sav\Dataset;

/** Internal boundary around the selected external SPSS reader/writer. */
interface SpssEngine
{
    /** @return array<string, mixed> */
    public function identity(): array;

    /** @return array<string, bool> */
    public function capabilities(): array;

    public function read(string $sourcePath): Dataset;

    public function write(string $targetPath, Dataset $dataset): void;
}
