<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model\Action;

use OpenStatSpec\Transformation\Model\RecodeAction;
use OpenStatSpec\Transformation\Model\ScalarValue;

final readonly class AssignValueAction implements RecodeAction
{
    public function __construct(private ScalarValue $value) {}

    public function type(): string
    {
        return 'assign';
    }

    public function value(): ScalarValue
    {
        return $this->value;
    }

    /** @return array{type: string, value: array<string, mixed>} */
    public function canonicalArray(): array
    {
        return ['type' => $this->type(), 'value' => $this->value->canonicalArray()];
    }
}
