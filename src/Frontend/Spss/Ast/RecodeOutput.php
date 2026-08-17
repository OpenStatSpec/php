<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class RecodeOutput
{
    public function __construct(
        public RecodeOutputKind $kind,
        public SourceSpan $span,
        public ?ScalarValue $value = null,
    ) {}
}
