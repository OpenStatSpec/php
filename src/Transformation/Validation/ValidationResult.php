<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Validation;

final readonly class ValidationResult
{
    /** @param list<ValidationViolation> $violations */
    public function __construct(private array $violations) {}

    public function isValid(): bool
    {
        return $this->violations === [];
    }

    /** @return list<ValidationViolation> */
    public function violations(): array
    {
        return $this->violations;
    }

    public function throwIfInvalid(): void
    {
        if ($this->violations !== []) {
            throw new InvalidTransformationPlan($this->violations);
        }
    }
}
