<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Support;

use RuntimeException;

final class SpecificationManifest
{
    public static function path(string $relative): string
    {
        $configured = getenv('OPENSTATSPEC_SPECIFICATION_DIR');
        $root = is_string($configured) && $configured !== ''
            ? $configured
            : dirname(__DIR__, 3) . '/specification';
        $path = $root . '/' . ltrim($relative, '/');
        if (!is_file($path)) {
            throw new RuntimeException('Pinned specification fixture is missing: ' . $relative);
        }
        return $path;
    }

    /** @return array<string, mixed> */
    public static function load(string $relative): array
    {
        $decoded = json_decode(
            (string) file_get_contents(self::path($relative)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (!is_array($decoded)) {
            throw new RuntimeException('Specification manifest must decode to an object.');
        }
        return $decoded;
    }
}
