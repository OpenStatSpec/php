<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use RuntimeException;

/** Exact IEEE-754 markers used by the supported SPSS engine for range endpoints. */
final class SpssMissingValueSentinel
{
    private const LOWEST_HEX = 'ffeffffffffffffe';
    private const HIGHEST_HEX = '7fefffffffffffff';

    public static function isLowest(int|float $value): bool
    {
        return self::hex((float) $value) === self::LOWEST_HEX;
    }

    public static function isHighest(int|float $value): bool
    {
        return self::hex((float) $value) === self::HIGHEST_HEX;
    }

    public static function lowest(): float
    {
        return self::fromHex(self::LOWEST_HEX);
    }

    public static function highest(): float
    {
        return self::fromHex(self::HIGHEST_HEX);
    }

    private static function hex(float $value): string
    {
        return bin2hex(pack('E', $value));
    }

    private static function fromHex(string $hex): float
    {
        $bytes = hex2bin($hex);
        if ($bytes === false) {
            throw new RuntimeException('Invalid SPSS binary64 sentinel.');
        }
        $value = unpack('Evalue', $bytes);
        if (!is_array($value) || !is_float($value['value'] ?? null)) {
            throw new RuntimeException('Could not decode the SPSS binary64 sentinel.');
        }
        return $value['value'];
    }
}
