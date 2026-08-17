<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Request;

final readonly class InputSchema
{
    /** @param non-empty-list<InputVariable> $variables */
    public function __construct(public array $variables) {}
}
