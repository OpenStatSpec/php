<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Request;

final readonly class SpssFrontendRequest
{
    public function __construct(
        public string $contract,
        public string $inputAlias,
        public InputSchema $inputSchema,
        public string $sourceText,
    ) {}
}
