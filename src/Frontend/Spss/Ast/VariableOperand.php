<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class VariableOperand implements ExpressionOperand
{
    public function __construct(public string $name, public SourceSpan $span) {}

    public function span(): SourceSpan
    {
        return $this->span;
    }
}
