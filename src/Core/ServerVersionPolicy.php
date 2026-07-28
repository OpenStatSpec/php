<?php

declare(strict_types=1);

namespace OpenStatSpec\Core;

/** Exact database-family versions claimed by the reference SQL profiles. */
final class ServerVersionPolicy
{
    /** @return array{claimed_supported: bool, matched_claim: ?string} */
    public static function assess(string $profile, string $serverVersion): array
    {
        $claim = self::claim($profile);
        $supported = match ($profile) {
            'mysql' => preg_match('/^(?:MySQL\s+)?8\.4(?:\.|$)/i', trim($serverVersion)) === 1,
            'mariadb' => preg_match('/^(?:MariaDB\s+)?11\.4(?:\.|$)/i', trim($serverVersion)) === 1,
            'postgresql' => preg_match('/^(?:PostgreSQL\s+)?17(?:\.|$)/i', trim($serverVersion)) === 1,
            'sqlite' => self::supportsSqlite($serverVersion),
            default => false,
        };

        return [
            'claimed_supported' => $supported,
            'matched_claim' => $supported ? $claim : null,
        ];
    }

    private static function supportsSqlite(string $serverVersion): bool
    {
        if (preg_match('/^(?:SQLite\s+)?(\d+\.\d+\.\d+)(?:\s|$)/i', trim($serverVersion), $matches) !== 1) {
            return false;
        }
        return version_compare($matches[1], '3.24.0', '>=')
            && version_compare($matches[1], '4.0.0', '<');
    }

    public static function claim(string $profile): string
    {
        return match ($profile) {
            'mysql' => 'MySQL 8.4.x',
            'mariadb' => 'MariaDB 11.4.x',
            'postgresql' => 'PostgreSQL 17.x',
            'sqlite' => 'SQLite >=3.24.0 <4.0.0',
            default => 'unsupported',
        };
    }
}
