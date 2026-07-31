<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

final readonly class ValueLabelGroup
{
    /**
     * @param non-empty-list<string> $variables
     * @param non-empty-list<ValueLabel> $labels
     */
    public function __construct(public array $variables, public array $labels) {}
}
