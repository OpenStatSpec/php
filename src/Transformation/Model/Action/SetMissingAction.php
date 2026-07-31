<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model\Action;

use OpenStatSpec\Transformation\Model\RecodeAction;

/** Assigns the source-neutral system-missing value represented by SQL NULL. */
final readonly class SetMissingAction implements RecodeAction
{
    public function type(): string
    {
        return 'set_missing';
    }

    /** @return array{type: string} */
    public function canonicalArray(): array
    {
        return ['type' => $this->type()];
    }
}
