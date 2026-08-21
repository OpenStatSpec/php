<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Diagnostic;

use RuntimeException;

class TransformationFailure extends RuntimeException
{
    /** @var non-empty-list<TransformationDiagnostic> */
    public readonly array $diagnostics;

    /** @param non-empty-list<TransformationDiagnostic> $diagnostics */
    public function __construct(array $diagnostics)
    {
        $this->diagnostics = $diagnostics;

        parent::__construct($diagnostics[0]->message);
    }

    public static function at(string $code, string $path, string $message, ?SourceSpan $span = null): self
    {
        return new self([new TransformationDiagnostic($code, $path, $message, $span)]);
    }

    public function diagnosticCode(): string
    {
        return $this->diagnostics[0]->code;
    }
}
