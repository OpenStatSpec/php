<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Binding;

use OpenStatSpec\Frontend\Spss\Ast\RecodeRule;

final readonly class BoundRecode implements BoundStatement
{
    /** @param non-empty-list<RecodeRule> $rules */
    public function __construct(
        public string $sourceVariable,
        public string $targetVariable,
        public array $rules,
    ) {}
}
