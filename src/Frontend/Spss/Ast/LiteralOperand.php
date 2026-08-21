<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class LiteralOperand implements ExpressionOperand
{
    public function __construct(public string $token, public SourceSpan $span) {}

    public function span(): SourceSpan
    {
        return $this->span;
    }
}
