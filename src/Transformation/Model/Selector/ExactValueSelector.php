<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model\Selector;

use OpenStatSpec\Transformation\Model\RecodeSelector;
use OpenStatSpec\Transformation\Model\ScalarValue;

final readonly class ExactValueSelector implements RecodeSelector
{
    public function __construct(private ScalarValue $value) {}

    public function type(): string
    {
        return 'exact';
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
