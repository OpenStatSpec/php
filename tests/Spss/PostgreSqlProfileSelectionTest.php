<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\SpssAdapter;
use OpenStatSpec\Spss\SpssEngine;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\PostgreSqlProfile;
use PDO;
use PHPUnit\Framework\TestCase;

final class PostgreSqlProfileSelectionTest extends TestCase
{
    public function testPostgreSqlDriverSelectsProfileAndFailsImportBeforeReadingSource(): void
    {
        $pdo = $this->pdoWithDriver('pgsql');
        $engine = $this->createMock(SpssEngine::class);
        $engine->expects($this->never())->method('read');

        self::assertInstanceOf(PostgreSqlProfile::class, (new Connection($pdo))->profile);

        $adapter = new SpssAdapter($pdo, $engine);
        $this->expectExceptionObject(new UnsupportedOperation(
            DiagnosticCode::SqlProfileOperationUnavailable,
            'The PostgreSQL profile is selected, but import is unavailable until the PostgreSQL wide-table implementation is complete. No tables were created.',
        ));

        $adapter->import('fixture.sav', 'fixture');
    }

    public function testPostgreSqlDriverSelectsProfileAndFailsExportBeforeWritingTarget(): void
    {
        $pdo = $this->pdoWithDriver('pgsql');
        $engine = $this->createMock(SpssEngine::class);
        $engine->expects($this->never())->method('write');

        $adapter = new SpssAdapter($pdo, $engine);
        $this->expectExceptionObject(new UnsupportedOperation(
            DiagnosticCode::SqlProfileOperationUnavailable,
            'The PostgreSQL profile is selected, but export is unavailable until the PostgreSQL wide-table implementation is complete. No tables were created.',
        ));

        $adapter->export('fixture', 'fixture.zsav');
    }

    private function pdoWithDriver(string $driver): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')
            ->with(PDO::ATTR_DRIVER_NAME)
            ->willReturn($driver);

        return $pdo;
    }
}
