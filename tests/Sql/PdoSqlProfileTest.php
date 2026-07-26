<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\MySqlProfile;
use OpenStatSpec\Sql\PostgreSqlProfile;
use OpenStatSpec\Sql\SqliteProfile;
use PHPUnit\Framework\TestCase;

final class PdoSqlProfileTest extends TestCase
{
    public function testProfilesDeclarePortableSqlRulesWithoutServerConnections(): void
    {
        $sqlite = new SqliteProfile();
        $postgres = new PostgreSqlProfile();
        $mysql = new MySqlProfile();

        self::assertSame('REAL', $sqlite->numericType());
        self::assertSame('DOUBLE PRECISION', $postgres->numericType());
        self::assertSame('LONGTEXT', $mysql->textType());
        self::assertSame('"name"', $postgres->quoteIdentifier('name'));
        self::assertSame("`name`", $mysql->quoteIdentifier('name'));
        self::assertSame(1599, $postgres->maximumSourceVariables());
        self::assertSame(1016, $mysql->maximumSourceVariables());
    }

    public function testProfilesRejectWideTablesBeyondDeclaredCapabilities(): void
    {
        $this->expectException(UnsupportedOperation::class);
        $this->expectExceptionObject(new UnsupportedOperation(
            DiagnosticCode::TargetCapabilityExceeded,
            'mysql supports at most 1016 source variables in one OpenStatSpec wide table.',
        ));

        (new MySqlProfile())->assertCanRepresent(1017);
    }
    public function testPostgreSqlMapsLongCollidingSourceNamesWithinIdentifierLimit(): void
    {
        $profile = new PostgreSqlProfile();
        $source = str_repeat('Very long source variable name ', 4);
        $first = $profile->physicalIdentifier($source, ['__case_ordinal' => true]);
        $second = $profile->physicalIdentifier($source, ['__case_ordinal' => true, $first => true]);

        self::assertSame(63, strlen($first));
        self::assertSame(63, strlen($second));
        self::assertNotSame($first, $second);
        self::assertStringEndsWith('_2', $second);
        self::assertSame('data', $profile->physicalIdentifier('###'));
    }
}
