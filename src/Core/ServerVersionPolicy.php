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
            'mysql' => preg_match('/^(?:MySQL\s+)?(?:8\.4|9\.7)(?:\.|$)/i', trim($serverVersion)) === 1,
            'mariadb' => preg_match('/^(?:MariaDB\s+)?(?:11\.4|11\.8|12\.3)(?:\.|$)/i', trim($serverVersion)) === 1,
            'dolt' => preg_match('/\A2\.2\.(?:[2-9]|[1-9]\d+)\z/', trim($serverVersion)) === 1,
            'postgresql' => preg_match('/^(?:PostgreSQL\s+)?(?:17|18)(?:\.|$)/i', trim($serverVersion)) === 1,
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
            'mysql' => 'MySQL 8.4.x or 9.7.x',
            'mariadb' => 'MariaDB 11.4.x, 11.8.x or 12.3.x',
            'dolt' => 'Dolt 2.2.x (>=2.2.2 <2.3.0)',
            'postgresql' => 'PostgreSQL 17.x or 18.x',
            'sqlite' => 'SQLite >=3.24.0 <4.0.0',
            default => 'unsupported',
        };
    }

    /** @return list<string> */
    public static function ciTestedVersions(string $profile): array
    {
        return match ($profile) {
            'mysql' => ['MySQL 8.4.11', 'MySQL 9.7.2'],
            'mariadb' => ['MariaDB 11.4.12', 'MariaDB 11.8.8', 'MariaDB 12.3.2'],
            'dolt' => ['Dolt 2.2.2', 'Dolt 2.2.3'],
            'postgresql' => ['PostgreSQL 17.10', 'PostgreSQL 18.4'],
            'sqlite' => ['active PDO SQLite version reported by CI'],
            default => [],
        };
    }

    public static function normalize(string $profile, string $serverVersion): ?string
    {
        $serverVersion = trim($serverVersion);
        if ($profile === 'dolt') {
            return $serverVersion === '' ? null : $serverVersion;
        }
        if (!in_array($profile, ['mysql', 'mariadb', 'postgresql', 'sqlite'], true)) {
            return null;
        }
        if (preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', $serverVersion, $matches) !== 1) {
            return null;
        }
        if ($profile === 'postgresql') {
            return $matches[1] . '.' . $matches[2];
        }

        return $matches[1] . '.' . $matches[2] . '.' . ($matches[3] ?? '0');
    }
}
