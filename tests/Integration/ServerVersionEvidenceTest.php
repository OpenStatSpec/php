<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

use OpenStatSpec\Core\ServerVersionPolicy;
use OpenStatSpec\Sql\Connection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerVersionEvidenceTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function services(): iterable
    {
        yield 'PostgreSQL' => ['OPENSTATSPEC_PG_DSN', 'OPENSTATSPEC_PG_USER', 'OPENSTATSPEC_PG_PASSWORD', 'OPENSTATSPEC_EXPECTED_POSTGRES_VERSION', 'postgresql'];
        yield 'MySQL' => ['OPENSTATSPEC_MYSQL_DSN', 'OPENSTATSPEC_MYSQL_USER', 'OPENSTATSPEC_MYSQL_PASSWORD', 'OPENSTATSPEC_EXPECTED_MYSQL_VERSION', 'mysql'];
        yield 'MariaDB' => ['OPENSTATSPEC_MARIADB_DSN', 'OPENSTATSPEC_MARIADB_USER', 'OPENSTATSPEC_MARIADB_PASSWORD', 'OPENSTATSPEC_EXPECTED_MARIADB_VERSION', 'mariadb'];
        yield 'Dolt' => ['OPENSTATSPEC_DOLT_DSN', 'OPENSTATSPEC_DOLT_USER', 'OPENSTATSPEC_DOLT_PASSWORD', 'OPENSTATSPEC_EXPECTED_DOLT_VERSION', 'dolt'];
    }

    #[DataProvider('services')]
    public function testLiveServerMatchesExactCiEvidence(
        string $dsnEnvironment,
        string $userEnvironment,
        string $passwordEnvironment,
        string $expectedEnvironment,
        string $expectedProfile,
    ): void {
        $dsn = getenv($dsnEnvironment);
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped(sprintf('%s is not configured.', $dsnEnvironment));
        }
        $expectedVersion = getenv($expectedEnvironment);
        self::assertIsString($expectedVersion, sprintf('%s is required for CI provenance.', $expectedEnvironment));
        self::assertNotSame('', $expectedVersion, sprintf('%s is required for CI provenance.', $expectedEnvironment));
        $user = getenv($userEnvironment);
        $password = getenv($passwordEnvironment);
        $connection = new Connection(new PDO(
            $dsn,
            is_string($user) ? $user : null,
            is_string($password) ? $password : null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ));

        self::assertSame($expectedProfile, $connection->profileName);
        self::assertTrue($connection->claimedSupported);
        self::assertSame(
            $expectedVersion,
            ServerVersionPolicy::normalize($connection->profileName, $connection->serverVersion),
        );
    }
}
