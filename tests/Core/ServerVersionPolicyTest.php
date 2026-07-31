<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Core;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\ServerVersionPolicy;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\SpssAdapter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerVersionPolicyTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool, ?string}> */
    public static function versions(): iterable
    {
        yield 'MySQL LTS lower patch supported' => ['mysql', '8.4.0', true, 'MySQL 8.4.x or 9.7.x'];
        yield 'MySQL LTS future patch supported' => ['mysql', '8.4.999', true, 'MySQL 8.4.x or 9.7.x'];
        yield 'MySQL latest supported' => ['mysql', '9.7.2', true, 'MySQL 8.4.x or 9.7.x'];
        yield 'MySQL old' => ['mysql', '8.0.40', false, null];
        yield 'MySQL unclaimed family' => ['mysql', '8.5.0', false, null];
        yield 'MySQL future family' => ['mysql', '9.8.0', false, null];
        yield 'MariaDB previous LTS lower patch supported' => ['mariadb', '11.4.0-MariaDB-ubu2404', true, 'MariaDB 11.4.x, 11.8.x or 12.3.x'];
        yield 'MariaDB previous LTS future patch supported' => ['mariadb', '11.4.999-MariaDB-ubu2404', true, 'MariaDB 11.4.x, 11.8.x or 12.3.x'];
        yield 'MariaDB LTS supported' => ['mariadb', '11.8.8-MariaDB-ubu2404', true, 'MariaDB 11.4.x, 11.8.x or 12.3.x'];
        yield 'MariaDB latest supported' => ['mariadb', '12.3.2-MariaDB-ubu2404', true, 'MariaDB 11.4.x, 11.8.x or 12.3.x'];
        yield 'MariaDB old' => ['mariadb', '10.11.11-MariaDB', false, null];
        yield 'MariaDB unclaimed family' => ['mariadb', '11.5.0-MariaDB', false, null];
        yield 'MariaDB future family' => ['mariadb', '12.4.0-MariaDB', false, null];
        yield 'Dolt floor supported' => ['dolt', '2.2.2', true, 'Dolt 2.2.x (>=2.2.2 <2.3.0)'];
        yield 'Dolt exact latest CI release supported' => ['dolt', '2.2.3', true, 'Dolt 2.2.x (>=2.2.2 <2.3.0)'];
        yield 'Dolt future patch supported' => ['dolt', '2.2.999', true, 'Dolt 2.2.x (>=2.2.2 <2.3.0)'];
        yield 'Dolt below floor 2.2.0' => ['dolt', '2.2.0', false, null];
        yield 'Dolt below floor 2.2.1' => ['dolt', '2.2.1', false, null];
        yield 'Dolt previous family' => ['dolt', '2.1.999', false, null];
        yield 'Dolt future family' => ['dolt', '2.3.0', false, null];
        yield 'Dolt prerelease suffix' => ['dolt', '2.2.3-rc1', false, null];
        yield 'Dolt malformed' => ['dolt', 'not-a-version', false, null];
        yield 'PostgreSQL previous major lower minor supported' => ['postgresql', '17.0', true, 'PostgreSQL 17.x or 18.x'];
        yield 'PostgreSQL previous major future minor supported' => ['postgresql', '17.99', true, 'PostgreSQL 17.x or 18.x'];
        yield 'PostgreSQL latest supported' => ['postgresql', '18.4 (Debian 18.4-1)', true, 'PostgreSQL 17.x or 18.x'];
        yield 'PostgreSQL old' => ['postgresql', '16.9', false, null];
        yield 'PostgreSQL future major' => ['postgresql', '19.0', false, null];
        yield 'SQLite lower boundary supported' => ['sqlite', '3.24.0', true, 'SQLite >=3.24.0 <4.0.0'];
        yield 'SQLite current supported' => ['sqlite', 'SQLite 3.46.1', true, 'SQLite >=3.24.0 <4.0.0'];
        yield 'SQLite too old' => ['sqlite', '3.23.1', false, null];
        yield 'SQLite future major' => ['sqlite', '4.0.0', false, null];
        yield 'SQLite malformed' => ['sqlite', '3.46', false, null];
        yield 'unknown' => ['mysql', 'not-a-version', false, null];
    }

    #[DataProvider('versions')]
    public function testExactClaims(string $profile, string $version, bool $supported, ?string $claim): void
    {
        self::assertSame([
            'claimed_supported' => $supported,
            'matched_claim' => $claim,
        ], ServerVersionPolicy::assess($profile, $version));
    }

    public function testClaimsAndExactCiEvidenceHaveSeparateCanonicalDeclarations(): void
    {
        self::assertSame(['MySQL 8.4.11', 'MySQL 9.7.2'], ServerVersionPolicy::ciTestedVersions('mysql'));
        self::assertSame(
            ['MariaDB 11.4.12', 'MariaDB 11.8.8', 'MariaDB 12.3.2'],
            ServerVersionPolicy::ciTestedVersions('mariadb'),
        );
        self::assertSame(['Dolt 2.2.2', 'Dolt 2.2.3'], ServerVersionPolicy::ciTestedVersions('dolt'));
        self::assertSame(['PostgreSQL 17.10', 'PostgreSQL 18.4'], ServerVersionPolicy::ciTestedVersions('postgresql'));
        self::assertSame([], ServerVersionPolicy::ciTestedVersions('mssql'));
        self::assertSame('unsupported', ServerVersionPolicy::claim('mssql'));
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function normalizedVersions(): iterable
    {
        yield 'MySQL patch' => ['mysql', '8.4.11', '8.4.11'];
        yield 'MariaDB package suffix' => ['mariadb', '11.4.12-MariaDB-ubu2404', '11.4.12'];
        yield 'PostgreSQL package suffix' => ['postgresql', '17.10 (Ubuntu 17.10-1)', '17.10'];
        yield 'Dolt floor product' => ['dolt', ' 2.2.2 ', '2.2.2'];
        yield 'Dolt latest evidence product' => ['dolt', '2.2.3', '2.2.3'];
        yield 'unknown profile' => ['mssql', '17.10', null];
        yield 'malformed' => ['mysql', 'not-a-version', null];
    }

    #[DataProvider('normalizedVersions')]
    public function testNormalizesStableProductVersionWidth(string $profile, string $version, ?string $expected): void
    {
        self::assertSame($expected, ServerVersionPolicy::normalize($profile, $version));
    }

    public function testDoltBelowFloorFailsBeforeDdl(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturnCallback(static fn(int $attribute): string => match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'mysql',
            PDO::ATTR_SERVER_VERSION => '8.0.33',
            default => '',
        });
        $pdo->method('query')->willReturnCallback(function (string $query): PDOStatement {
            $value = match ($query) {
                'SELECT @@version' => '8.0.33',
                'SELECT @@version_comment' => 'Dolt',
                'SELECT DOLT_VERSION()' => '2.2.1',
                default => throw new \LogicException('Unexpected identity probe: ' . $query),
            };
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('fetchColumn')->willReturn($value);

            return $statement;
        });
        $pdo->expects(self::never())->method('exec');
        $pdo->expects(self::never())->method('prepare');

        $adapter = new SpssAdapter($pdo);
        try {
            $adapter->migrateCatalog();
            self::fail('A Dolt release below the claimed floor was accepted.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
            self::assertStringContainsString('outside the claimed profile Dolt 2.2.x (>=2.2.2 <2.3.0)', $exception->getMessage());
        }
    }

    public function testUnsupportedServerFailsBeforeDdl(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturnCallback(static fn(int $attribute): string => match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'mysql',
            PDO::ATTR_SERVER_VERSION => '8.0.40',
            default => '',
        });
        $pdo->method('query')->willReturnCallback(function (string $query): PDOStatement {
            $value = match ($query) {
                'SELECT @@version' => '8.0.40',
                'SELECT @@version_comment' => 'MySQL Community Server - GPL',
                default => throw new \LogicException('Unexpected identity probe: ' . $query),
            };
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('fetchColumn')->willReturn($value);

            return $statement;
        });
        $pdo->expects(self::never())->method('exec');

        $adapter = new SpssAdapter($pdo);
        try {
            $adapter->migrateCatalog();
            self::fail('Unsupported server version was accepted.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
            self::assertStringContainsString('outside the claimed profile MySQL 8.4.x or 9.7.x', $exception->getMessage());
        }
    }
}
