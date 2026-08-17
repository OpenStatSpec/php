<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class ScalarValue
{
    public function __construct(
        public string $value,
        public ?string $numericToken,
        public SourceSpan $span,
    ) {}

    public function isNumeric(): bool
    {
        return $this->numericToken !== null;
    }
}
