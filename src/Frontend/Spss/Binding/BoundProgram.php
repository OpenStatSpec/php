<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Binding;

final readonly class BoundProgram
{
    /** @param non-empty-list<BoundStatement> $statements */
    public function __construct(public string $inputAlias, public array $statements) {}
}
