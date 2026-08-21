<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan\Value;

use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;

final readonly class Binary64Value implements TypedValue
{
    private function __construct(public string $bits) {}

    public static function fromBits(string $bits): self
    {
        if (preg_match('/\\A[0-9a-f]{16}\\z/D', $bits) !== 1) {
            throw TransformationFailure::at('plan_schema_invalid', '$', 'binary64 bits must be 16 lowercase hexadecimal characters.');
        }

        if (substr($bits, 0, 3) === '7ff' || substr($bits, 0, 3) === 'fff') {
            throw TransformationFailure::at('plan_schema_invalid', '$', 'binary64 values must be finite.');
        }

        if ($bits === '8000000000000000') {
            throw TransformationFailure::at('plan_schema_invalid', '$', 'Negative zero is not a canonical plan value.');
        }

        return new self($bits);
    }

    /** @return array{type: string, bits: string} */
    public function canonicalArray(): array
    {
        return ['type' => 'binary64', 'bits' => $this->bits];
    }

    public function canonicalKey(): string
    {
        return 'binary64:' . $this->bits;
    }

    public function number(): float
    {
        $binary = hex2bin($this->bits);
        if ($binary === false) {
            throw new \LogicException('Validated binary64 bits could not be decoded.');
        }

        $decoded = unpack('Evalue', $binary);
        if (!is_array($decoded) || !isset($decoded['value']) || !is_float($decoded['value'])) {
            throw new \LogicException('Validated binary64 bits could not be unpacked.');
        }

        return $decoded['value'];
    }

    /** Decimal text whose conversion back to binary64 preserves the exact bits. */
    public function decimal(): string
    {
        $decimal = sprintf('%.17g', $this->number());
        $separator = localeconv()['decimal_point'] ?? '.';
        if (is_string($separator) && $separator !== '' && $separator !== '.') {
            $decimal = str_replace($separator, '.', $decimal);
        }

        return strtolower($decimal);
    }
}
