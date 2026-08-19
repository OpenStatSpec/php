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
    ) {
        if ($startOffset > $endOffset) {
            throw new \InvalidArgumentException('SourceSpan start offset must not exceed its end offset.');
        }
        if ($startLine > $endLine || ($startLine === $endLine && $startColumn > $endColumn)) {
            throw new \InvalidArgumentException('SourceSpan start position must not exceed its end position.');
        }
    }

    public static function cover(self $start, self $end): self
    {
        if ($start->startOffset > $end->endOffset) {
            throw new \InvalidArgumentException('SourceSpan::cover() requires the end span to start at or after the start span.');
        }
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
