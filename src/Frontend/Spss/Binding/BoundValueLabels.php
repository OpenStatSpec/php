<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Binding;

use OpenStatSpec\Frontend\Spss\Ast\ValueLabel;

final readonly class BoundValueLabels implements BoundStatement
{
    /** @param non-empty-list<ValueLabel> $labels */
    public function __construct(public string $variable, public array $labels) {}
}
