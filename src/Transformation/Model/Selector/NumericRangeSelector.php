<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model\Selector;

use OpenStatSpec\Transformation\Model\RecodeSelector;
use OpenStatSpec\Transformation\Model\ScalarValue;

/** An inclusive numeric interval; a null bound means negative/positive infinity. */
final readonly class NumericRangeSelector implements RecodeSelector
{
    public function __construct(
        private ?float $lower,
        private ?float $upper,
    ) {}

    public function type(): string
    {
        return 'numeric_range';
    }

    public function lower(): ?float
    {
        return $this->lower;
    }

    public function upper(): ?float
    {
        return $this->upper;
    }

    /** @return array{type: string, lower: ?array<string, mixed>, upper: ?array<string, mixed>, bounds: string} */
    public function canonicalArray(): array
    {
        return [
            'type' => $this->type(),
            'lower' => $this->lower === null ? null : ScalarValue::number($this->lower)->canonicalArray(),
            'upper' => $this->upper === null ? null : ScalarValue::number($this->upper)->canonicalArray(),
            'bounds' => 'inclusive',
        ];
    }
}
