<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;
use PDOException;

/** Fail-closed database-product identity derived from the active PDO connection. */
final readonly class ServerIdentity
{
    /** @param array<string, string|null> $probeResults */
    private function __construct(
        public string $driverName,
        public string $profileName,
        public string $serverVersion,
        public string $rawServerVersion,
        public string $identitySource,
        public array $probeResults,
    ) {}

    public static function detect(PDO $pdo): self
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!is_string($driver)) {
            throw new UnsupportedOperation(
                DiagnosticCode::UnsupportedSqlDriver,
                'The PDO connection did not report a usable SQL driver name.',
            );
        }

        $rawServerVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        if ($driver === 'mysql') {
            return self::detectMySqlFamily($pdo, $rawServerVersion);
        }

        $profileName = match ($driver) {
            'sqlite' => 'sqlite',
            'pgsql' => 'postgresql',
            default => throw new UnsupportedOperation(
                DiagnosticCode::UnsupportedSqlDriver,
                sprintf('The PDO driver "%s" has no OpenStatSpec SQL profile.', $driver),
            ),
        };

        return new self(
            $driver,
            $profileName,
            $rawServerVersion,
            $rawServerVersion,
            'PDO::ATTR_SERVER_VERSION',
            ['PDO::ATTR_SERVER_VERSION' => $rawServerVersion],
        );
    }

    private static function detectMySqlFamily(PDO $pdo, string $rawServerVersion): self
    {
        $version = self::requiredScalar($pdo, 'SELECT @@version', '@@version');
        $versionComment = self::scalar($pdo, 'SELECT @@version_comment');
        if ($versionComment === null || trim($versionComment) === '') {
            throw self::identityFailure('The MySQL-compatible server did not provide a non-empty @@version_comment.');
        }

        $version = trim($version);
        $versionComment = trim($versionComment);
        $normalizedVersion = strtolower($version);
        $normalizedComment = strtolower($versionComment);
        if ($normalizedComment === 'dolt') {
            $doltVersion = self::scalar($pdo, 'SELECT DOLT_VERSION()');
            if ($doltVersion === null || trim($doltVersion) === '') {
                throw self::identityFailure(
                    'Dolt identity requires exact @@version_comment = Dolt and a non-empty DOLT_VERSION() result.',
                );
            }

            return new self(
                'mysql',
                'dolt',
                trim($doltVersion),
                $rawServerVersion,
                '@@version + @@version_comment + DOLT_VERSION()',
                [
                    'PDO::ATTR_SERVER_VERSION' => $rawServerVersion,
                    '@@version' => $version,
                    '@@version_comment' => $versionComment,
                    'DOLT_VERSION()' => trim($doltVersion),
                ],
            );
        }

        $versionClaimsMariaDb = str_contains($normalizedVersion, 'mariadb');
        $commentClaimsMariaDb = str_contains($normalizedComment, 'mariadb');
        $commentClaimsMySql = str_contains($normalizedComment, 'mysql');
        if ($versionClaimsMariaDb !== $commentClaimsMariaDb
            || ($commentClaimsMariaDb && $commentClaimsMySql)
        ) {
            throw self::identityFailure(
                'The MySQL-compatible server returned conflicting product identity signals.',
            );
        }

        $profileName = match (true) {
            $versionClaimsMariaDb && $commentClaimsMariaDb => 'mariadb',
            !$versionClaimsMariaDb && $commentClaimsMySql => 'mysql',
            default => throw self::identityFailure(
                'The MySQL-compatible server product is unknown; only claimed MySQL, MariaDB, and Dolt identities are supported.',
            ),
        };

        return new self(
            'mysql',
            $profileName,
            $version,
            $rawServerVersion,
            '@@version + @@version_comment',
            [
                'PDO::ATTR_SERVER_VERSION' => $rawServerVersion,
                '@@version' => $version,
                '@@version_comment' => $versionComment,
                'DOLT_VERSION()' => null,
            ],
        );
    }

    private static function requiredScalar(PDO $pdo, string $sql, string $probeName): string
    {
        $value = self::scalar($pdo, $sql);
        if ($value === null || trim($value) === '') {
            throw self::identityFailure(
                sprintf('The MySQL-compatible server did not provide a non-empty %s.', $probeName),
            );
        }

        return $value;
    }

    private static function identityFailure(string $message): UnsupportedOperation
    {
        return new UnsupportedOperation(DiagnosticCode::TargetCapabilityExceeded, $message);
    }

    private static function scalar(PDO $pdo, string $sql): ?string
    {
        try {
            $statement = $pdo->query($sql);
            $value = $statement === false ? false : $statement->fetchColumn();
        } catch (PDOException) {
            return null;
        }

        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
