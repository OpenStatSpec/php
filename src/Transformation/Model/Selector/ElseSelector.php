<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model\Selector;

use OpenStatSpec\Transformation\Model\RecodeSelector;

/** Selects values not matched by an earlier rule. */
final readonly class ElseSelector implements RecodeSelector
{
    public function type(): string
    {
        return 'else';
    }

    /** @return array{type: string} */
    public function canonicalArray(): array
    {
        return ['type' => $this->type()];
    }
}
