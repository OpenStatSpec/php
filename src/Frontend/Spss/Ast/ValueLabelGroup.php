<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class ValueLabelGroup
{
    /**
     * @param non-empty-list<string> $variables
     * @param non-empty-list<ValueLabel> $labels
     */
    public function __construct(
        public array $variables,
        /** @var non-empty-list<SourceSpan> */
        public array $variableSpans,
        public array $labels,
        public SourceSpan $span,
    ) {}
}
