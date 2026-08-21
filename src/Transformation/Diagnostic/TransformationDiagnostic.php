<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Diagnostic;

final readonly class TransformationDiagnostic
{
    public function __construct(
        public string $code,
        public string $path,
        public string $message,
        public ?SourceSpan $span = null,
    ) {}
}
