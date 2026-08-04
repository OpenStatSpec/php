<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Binding;

final readonly class BoundDeleteVariable implements BoundStatement
{
    public function __construct(public string $variable) {}
}
