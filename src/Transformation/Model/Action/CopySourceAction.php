<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model\Action;

use OpenStatSpec\Transformation\Model\RecodeAction;

/** Assigns the current source value to the target variable. */
final readonly class CopySourceAction implements RecodeAction
{
    public function type(): string
    {
        return 'copy_source';
    }

    /** @return array{type: string} */
    public function canonicalArray(): array
    {
        return ['type' => $this->type()];
    }
}
