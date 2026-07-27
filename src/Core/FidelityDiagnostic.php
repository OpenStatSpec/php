<?php

declare(strict_types=1);

namespace OpenStatSpec\Core;

/** A machine-readable statement about an observed or deferred fidelity boundary. */
final readonly class FidelityDiagnostic
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public string $code,
        public string $message,
        public FidelitySeverity $severity = FidelitySeverity::Warning,
        public array $details = [],
        public ?string $sourceItem = null,
    ) {}

    /** @return array{code: string, message: string, severity: string, details: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'severity' => $this->severity->value,
            'details' => $this->details,
            'source_item' => $this->sourceItem,
        ];
    }
}
