<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model\Selector;

use OpenStatSpec\Transformation\Model\RecodeSelector;

/** Selects the source-neutral system-missing value represented by SQL NULL. */
final readonly class MissingValueSelector implements RecodeSelector
{
    public function type(): string
    {
        return 'missing';
    }

    /** @return array{type: string} */
    public function canonicalArray(): array
    {
        return ['type' => $this->type()];
    }
}
