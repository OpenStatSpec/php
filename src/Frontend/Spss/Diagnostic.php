<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

final readonly class Diagnostic
{
    public function __construct(
        public int $line,
        public int $column,
        public string $message,
    ) {}
}
