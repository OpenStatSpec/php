<?php

declare(strict_types=1);

namespace OpenStatSpec\Core;

use InvalidArgumentException;

final class Binary64
{
    /**
     * Encode a numeric SPSS value without PDO's default
     * precision-losing float-to-string conversion.
     */
    public static function encode(int|float $value): string
    {
        $float = (float) $value;
        if (!is_finite($float)) {
            throw new InvalidArgumentException('SPSS binary64 dictionary values must be finite.');
        }

        $encoded = sprintf('%.17H', $float);

        return strpbrk($encoded, '.eE') === false ? $encoded . '.0' : $encoded;
    }
}
