<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Binding;

final readonly class BoundProgram
{
    /** @param list<BoundStatement> $statements */
    public function __construct(public string $datasetId, public array $statements) {}
}
