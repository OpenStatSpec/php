<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Canonical;

final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::sortObjects($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private static function sortObjects(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::sortObjects(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortObjects($item);
        }

        return $value;
    }
}
