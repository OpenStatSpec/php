<?php

declare(strict_types=1);

namespace OpenStatSpec\Core;

use InvalidArgumentException;
use JsonException;

final class Binary64
{
    /**
     * Encode a numeric SPSS dictionary value without PDO's default
     * precision-losing float-to-string conversion.
     *
     * @throws JsonException
     */
    public static function encode(int|float $value): string
    {
        $float = (float) $value;
        if (!is_finite($float)) {
            throw new InvalidArgumentException('SPSS binary64 dictionary values must be finite.');
        }

        return json_encode($float, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
