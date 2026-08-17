<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Diagnostic;

final readonly class SourceSpan
{
    public function __construct(
        public int $startOffset,
        public int $endOffset,
        public int $startLine = 1,
        public int $startColumn = 1,
        public int $endLine = 1,
        public int $endColumn = 1,
    ) {}

    public static function cover(self $start, self $end): self
    {
        return new self(
            $start->startOffset,
            $end->endOffset,
            $start->startLine,
            $start->startColumn,
            $end->endLine,
            $end->endColumn,
        );
    }
}
