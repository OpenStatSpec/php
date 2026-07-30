<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\DoltProfile;
use OpenStatSpec\Sql\ServerIdentity;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ServerIdentityTest extends TestCase
{
    public function testDoltRequiresEverySignalAndUsesProductVersion(): void
    {
        $pdo = $this->mysqlPdo('8.0.33', [
            'SELECT @@version' => '8.0.33',
            'SELECT @@version_comment' => ' Dolt ',
            'SELECT DOLT_VERSION()' => '2.2.2',
        ]);

        $identity = ServerIdentity::detect($pdo);
        self::assertSame('dolt', $identity->profileName);
        self::assertSame('2.2.2', $identity->serverVersion);
        self::assertSame('8.0.33', $identity->rawServerVersion);
        self::assertSame('@@version + @@version_comment + DOLT_VERSION()', $identity->identitySource);
        self::assertSame([
            'PDO::ATTR_SERVER_VERSION' => '8.0.33',
            '@@version' => '8.0.33',
            '@@version_comment' => 'Dolt',
            'DOLT_VERSION()' => '2.2.2',
        ], $identity->probeResults);

        $connection = new Connection($pdo);
        self::assertTrue($connection->claimedSupported);
        self::assertSame('Dolt 2.2.2', $connection->matchedClaim);
        self::assertInstanceOf(DoltProfile::class, $connection->profile);
    }

    public function testDoltCommentWithoutProductVersionFailsClosedEvenWithSupportedLookingWireVersion(): void
    {
        $pdo = $this->mysqlPdo('8.4.6', [
            'SELECT @@version' => '8.4.6',
            'SELECT @@version_comment' => 'Dolt',
            'SELECT DOLT_VERSION()' => null,
        ]);

        try {
            new Connection($pdo);
            self::fail('A Dolt identity without DOLT_VERSION() must fail closed.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
            self::assertStringContainsString('DOLT_VERSION()', $exception->getMessage());
        }
    }

    public function testNonDoltCommentDoesNotProbeDoltFunctionAndUsesMySqlProfile(): void
    {
        $pdo = $this->nonDoltPdo('8.4.6', 'MySQL Community Server - GPL');

        $connection = new Connection($pdo);
        self::assertSame('mysql', $connection->profileName);
        self::assertSame('8.4.6', $connection->serverVersion);
        self::assertSame('@@version + @@version_comment', $connection->identitySource);
        self::assertTrue($connection->claimedSupported);
        self::assertSame('MySQL 8.4.x or 9.7.x', $connection->matchedClaim);
    }

    public function testNonDoltCommentDoesNotProbeDoltFunctionAndUsesMariaDbProfile(): void
    {
        $version = '11.4.5-MariaDB-ubu2404';
        $pdo = $this->nonDoltPdo($version, 'mariadb.org binary distribution');

        $connection = new Connection($pdo);
        self::assertSame('mariadb', $connection->profileName);
        self::assertSame($version, $connection->serverVersion);
        self::assertSame('@@version + @@version_comment', $connection->identitySource);
        self::assertTrue($connection->claimedSupported);
        self::assertSame('MariaDB 11.4.x, 11.8.x or 12.3.x', $connection->matchedClaim);
    }

    public function testUnvalidatedDoltVersionIsDetectedButNotClaimed(): void
    {
        $pdo = $this->mysqlPdo('8.0.33', [
            'SELECT @@version' => '8.0.33',
            'SELECT @@version_comment' => 'Dolt',
            'SELECT DOLT_VERSION()' => '2.2.3',
        ]);

        $connection = new Connection($pdo);
        self::assertSame('dolt', $connection->profileName);
        self::assertSame('2.2.3', $connection->serverVersion);
        self::assertFalse($connection->claimedSupported);
        self::assertNull($connection->matchedClaim);
    }

    public function testUnknownMySqlWireProductFailsBeforeAnyMutation(): void
    {
        $pdo = $this->mysqlPdo('8.4.6', [
            'SELECT @@version' => '8.4.6',
            'SELECT @@version_comment' => 'Acme compatible database',
        ]);
        $pdo->expects(self::never())->method('exec');
        $pdo->expects(self::never())->method('prepare');

        try {
            new Connection($pdo);
            self::fail('An unknown MySQL-wire product was accepted.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
            self::assertStringContainsString('product is unknown', $exception->getMessage());
        }
    }

    public function testConflictingMySqlAndMariaDbSignalsFailBeforeAnyMutation(): void
    {
        $pdo = $this->mysqlPdo('11.4.5-MariaDB', [
            'SELECT @@version' => '11.4.5-MariaDB',
            'SELECT @@version_comment' => 'MySQL Community Server - GPL',
        ]);
        $pdo->expects(self::never())->method('exec');
        $pdo->expects(self::never())->method('prepare');

        try {
            new Connection($pdo);
            self::fail('Conflicting MySQL-family identity signals were accepted.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
            self::assertStringContainsString('conflicting product identity', $exception->getMessage());
        }
    }

    public function testMissingVersionProbeFailsBeforeDoltProbeOrMutation(): void
    {
        $pdo = $this->mysqlPdo('8.0.33', [
            'SELECT @@version' => null,
            'SELECT @@version_comment' => 'Dolt',
            'SELECT DOLT_VERSION()' => '2.2.2',
        ]);
        $pdo->expects(self::never())->method('exec');
        $pdo->expects(self::never())->method('prepare');

        try {
            new Connection($pdo);
            self::fail('A missing @@version probe was accepted.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
            self::assertStringContainsString('@@version', $exception->getMessage());
        }
    }

    private function nonDoltPdo(string $wireVersion, string $versionComment): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturnCallback(static fn(int $attribute): string => match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'mysql',
            PDO::ATTR_SERVER_VERSION => $wireVersion,
            default => '',
        });
        $pdo->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $query) use ($wireVersion, $versionComment): PDOStatement {
                $value = match ($query) {
                    'SELECT @@version' => $wireVersion,
                    'SELECT @@version_comment' => $versionComment,
                    default => throw new \LogicException('Unexpected identity probe: ' . $query),
                };
                $statement = $this->createMock(PDOStatement::class);
                $statement->method('fetchColumn')->willReturn($value);

                return $statement;
            });

        return $pdo;
    }

    /**
     * @param array<string, string|null> $queryValues
     * @return PDO&MockObject
     */
    private function mysqlPdo(string $wireVersion, array $queryValues): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturnCallback(static fn(int $attribute): string => match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'mysql',
            PDO::ATTR_SERVER_VERSION => $wireVersion,
            default => '',
        });
        $pdo->method('query')->willReturnCallback(function (string $query) use ($queryValues): PDOStatement|false {
            if (!array_key_exists($query, $queryValues)) {
                return false;
            }
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('fetchColumn')->willReturn($queryValues[$query]);

            return $statement;
        });

        return $pdo;
    }
}
