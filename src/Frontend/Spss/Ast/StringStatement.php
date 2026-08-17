<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class StringStatement implements Statement
{
    /** @param list<string> $variables */
    public function __construct(
        public int $lineNumber,
        public array $variables,
        public int $width,
        public SourceSpan $commandSpan,
        public SourceSpan $span,
    ) {}

    public function line(): int
    {
        return $this->lineNumber;
    }
}
