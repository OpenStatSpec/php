<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Binding;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;
use OpenStatSpec\Transformation\Plan\Recode\RecodeRule;
use OpenStatSpec\Transformation\Plan\Recode\Result;
use OpenStatSpec\Transformation\Plan\TargetMode;

final readonly class BoundRecode implements BoundStatement
{
    /** @param non-empty-list<RecodeRule> $rules */
    public function __construct(
        public string $sourceVariable,
        public string $targetVariable,
        public TargetMode $targetMode,
        public array $rules,
        public Result $unmatched,
        public SourceSpan $span,
    ) {}
}
