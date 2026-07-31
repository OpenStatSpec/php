<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

final readonly class RecodeStatement implements Statement
{
    /**
     * @param non-empty-list<string> $sources
     * @param non-empty-list<RecodeRule> $rules
     * @param list<string> $targets
     */
    public function __construct(
        public int $sourceLine,
        public array $sources,
        public array $rules,
        public array $targets,
    ) {}

    public function line(): int
    {
        return $this->sourceLine;
    }
}
