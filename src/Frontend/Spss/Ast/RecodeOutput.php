<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

final readonly class RecodeOutput
{
    public function __construct(
        public RecodeOutputKind $kind,
        public ?ScalarValue $value = null,
    ) {}
}
