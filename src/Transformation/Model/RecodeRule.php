<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model;

final readonly class RecodeRule
{
    public function __construct(
        private RecodeSelector $selector,
        private RecodeAction $action,
    ) {}

    public function selector(): RecodeSelector
    {
        return $this->selector;
    }

    public function action(): RecodeAction
    {
        return $this->action;
    }

    /** @return array{selector: array<string, mixed>, action: array<string, mixed>} */
    public function canonicalArray(): array
    {
        return [
            'selector' => $this->selector->canonicalArray(),
            'action' => $this->action->canonicalArray(),
        ];
    }
}
