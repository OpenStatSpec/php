<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Core;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\ServerVersionPolicy;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\SpssAdapter;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerVersionPolicyTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool, ?string}> */
    public static function versions(): iterable
    {
        yield 'MySQL supported' => ['mysql', '8.4.3', true, 'MySQL 8.4.x'];
        yield 'MySQL old' => ['mysql', '8.0.40', false, null];
        yield 'MariaDB supported' => ['mariadb', '11.4.5-MariaDB-ubu2404', true, 'MariaDB 11.4.x'];
        yield 'MariaDB old' => ['mariadb', '10.11.11-MariaDB', false, null];
        yield 'PostgreSQL supported' => ['postgresql', '17.5 (Ubuntu 17.5-1)', true, 'PostgreSQL 17.x'];
        yield 'PostgreSQL old' => ['postgresql', '16.9', false, null];
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

    public function testUnsupportedServerFailsBeforeDdl(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturnCallback(static fn(int $attribute): string => match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'mysql',
            PDO::ATTR_SERVER_VERSION => '8.0.40',
            default => '',
        });
        $pdo->expects(self::never())->method('exec');

        $adapter = new SpssAdapter($pdo);
        try {
            $adapter->migrateCatalog();
            self::fail('Unsupported server version was accepted.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
            self::assertStringContainsString('outside the claimed profile MySQL 8.4.x', $exception->getMessage());
        }
    }
}
