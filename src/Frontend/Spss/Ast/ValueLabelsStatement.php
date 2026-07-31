<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

final readonly class ValueLabelsStatement implements Statement
{
    /** @param non-empty-list<ValueLabelGroup> $groups */
    public function __construct(public int $sourceLine, public array $groups) {}

    public function line(): int
    {
        return $this->sourceLine;
    }
}
