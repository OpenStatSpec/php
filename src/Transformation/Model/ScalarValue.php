<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model;

use InvalidArgumentException;

/** A typed statistical scalar; numeric values use IEEE-754 binary64 identity. */
final readonly class ScalarValue
{
    private function __construct(
        private string $type,
        private float|string $value,
    ) {}

    public static function number(int|float $value): self
    {
        return new self('number', (float) $value);
    }

    public static function string(string $value): self
    {
        return new self('string', $value);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function value(): float|string
    {
        return $this->value;
    }

    public function numberValue(): float
    {
        if (!is_float($this->value)) {
            throw new InvalidArgumentException('A string scalar has no numeric value.');
        }

        return $this->value;
    }

    public function stringValue(): string
    {
        if (!is_string($this->value)) {
            throw new InvalidArgumentException('A numeric scalar has no string value.');
        }

        return $this->value;
    }

    /** @return array{type: string, binary64?: string, value?: string} */
    public function canonicalArray(): array
    {
        if (is_string($this->value)) {
            return ['type' => 'string', 'value' => $this->value];
        }

        return ['type' => 'number', 'binary64' => bin2hex(pack('E', $this->normalisedNumber()))];
    }

    public function identity(): string
    {
        return $this->type . ':' . ($this->canonicalArray()['binary64'] ?? $this->value);
    }

    private function normalisedNumber(): float
    {
        $value = $this->value;
        if (!is_float($value)) {
            throw new InvalidArgumentException('A string scalar has no numeric representation.');
        }

        // IEEE-754 considers both zero representations equal for recoding.
        return $value === 0.0 ? 0.0 : $value;
    }
}
