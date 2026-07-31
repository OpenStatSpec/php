<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Validation;

final readonly class ValidationViolation
{
    public function __construct(
        public string $code,
        public string $path,
        public string $message,
    ) {}

    /** @return array{code: string, path: string, message: string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'path' => $this->path, 'message' => $this->message];
    }
}
