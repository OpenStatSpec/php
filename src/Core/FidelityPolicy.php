<?php

declare(strict_types=1);

namespace OpenStatSpec\Core;

/** Enforces explicit acknowledgement before a known lossy export can write a file. */
final class FidelityPolicy
{
    /**
     * @param list<FidelityDiagnostic> $diagnostics
     * @param list<string> $allowLoss
     */
    public static function assertExportAllowed(array $diagnostics, array $allowLoss): void
    {
        $accepted = array_fill_keys($allowLoss, true);
        $rejected = [];
        foreach ($diagnostics as $diagnostic) {
            if (!isset($accepted[$diagnostic->code])) {
                $rejected[$diagnostic->code] = true;
            }
        }

        if ($rejected !== []) {
            throw new UnsupportedOperation(
                DiagnosticCode::FidelityLossRequiresAcceptance,
                'Export requires explicit allowLoss acknowledgement for: ' . implode(', ', array_keys($rejected)),
            );
        }
    }
}
