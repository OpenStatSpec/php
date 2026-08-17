<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Recode;

final readonly class RecodeRule
{
    public function __construct(
        public RecodeMatch $match,
        public Result $result,
    ) {}

    /** @return array{match: array<string, mixed>, result: array<string, mixed>} */
    public function canonicalArray(): array
    {
        return ['match' => $this->match->canonicalArray(), 'result' => $this->result->canonicalArray()];
    }
}
