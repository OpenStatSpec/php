<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

/** Read-only Dolt repository state captured around an in-place transformation. */
final readonly class DoltEvidence
{
    /** @param list<string> $dirtyTables */
    public function __construct(
        private string $branch,
        private string $head,
        private array $dirtyTables,
    ) {}

    public function branch(): string
    {
        return $this->branch;
    }

    public function head(): string
    {
        return $this->head;
    }

    /** @return list<string> */
    public function dirtyTables(): array
    {
        return $this->dirtyTables;
    }

    public function isClean(): bool
    {
        return $this->dirtyTables === [];
    }

    /** @return array{branch: string, head: string, clean: bool, dirty_tables: list<string>} */
    public function toArray(): array
    {
        return [
            'branch' => $this->branch,
            'head' => $this->head,
            'clean' => $this->isClean(),
            'dirty_tables' => $this->dirtyTables,
        ];
    }
}
