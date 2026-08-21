<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class RecodeStatement implements Statement
{
    /**
     * @param non-empty-list<string> $sources
     * @param non-empty-list<RecodeRule> $rules
     * @param list<string> $targets
     */
    public function __construct(
        public int $sourceLine,
        public array $sources,
        /** @var non-empty-list<SourceSpan> */
        public array $sourceSpans,
        public array $rules,
        public array $targets,
        /** @var list<SourceSpan> */
        public array $targetSpans,
        public SourceSpan $span,
    ) {}

    public function line(): int
    {
        return $this->sourceLine;
    }
}
