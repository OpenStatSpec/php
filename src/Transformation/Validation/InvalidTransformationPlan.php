<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Validation;

use InvalidArgumentException;

final class InvalidTransformationPlan extends InvalidArgumentException
{
    /** @param non-empty-list<ValidationViolation> $violations */
    public function __construct(private readonly array $violations)
    {
        $first = $violations[0];
        parent::__construct(sprintf(
            'Invalid transformation plan (%s at %s): %s',
            $first->code,
            $first->path,
            $first->message,
        ));
    }

    /** @return non-empty-list<ValidationViolation> */
    public function violations(): array
    {
        return $this->violations;
    }
}
