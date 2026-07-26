<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use OpenStatSpec\Core\FidelityDiagnostic;

/** Outcome of an imported SAV or ZSAV dataset. */
final readonly class SpssImportResult
{
    /** @param list<FidelityDiagnostic> $diagnostics */
    public function __construct(
        public string $operationId,
        public string $datasetName,
        public int $caseCount,
        public array $diagnostics,
    ) {}
}
