<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Binding;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class BoundExecute implements BoundStatement
{
    public function __construct(public SourceSpan $span) {}
}
