<?php

declare(strict_types=1);

namespace OpenStatSpec\Core;

/** A machine-readable statement about metadata that was not preserved. */
final readonly class FidelityDiagnostic
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}
}
