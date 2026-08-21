<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class RecodeRule
{
    public function __construct(
        public RecodeInput $input,
        public RecodeOutput $output,
        public SourceSpan $span,
    ) {}
}
