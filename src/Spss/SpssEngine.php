<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use SPSS\Sav\Dataset;

/** Internal boundary around the selected external SPSS reader/writer. */
interface SpssEngine
{
    /** @return array<string, string|null> */
    public function identity(): array;

    public function read(string $sourcePath): Dataset;

    public function write(string $targetPath, Dataset $dataset): void;
}
