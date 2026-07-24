<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use OpenStatSpec\Core\FidelityDiagnostic;

/**
 * Outcome of a SAV export attempt.
 *
 * @param list<FidelityDiagnostic> $diagnostics
 */
final readonly class SpssExportResult
{
    /** @param list<FidelityDiagnostic> $diagnostics */
    public function __construct(
        public string $datasetName,
        public string $targetPath,
        public int $caseCount,
        public array $diagnostics,
    ) {}
}
