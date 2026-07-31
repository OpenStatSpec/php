<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\ServerVersionPolicy;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\DoltProfile;
use OpenStatSpec\Sql\MySqlProfile;
use OpenStatSpec\Sql\PostgreSqlProfile;
use OpenStatSpec\Sql\SqliteProfile;
use OpenStatSpec\Sql\OperationJournal;
use PDO;
use PDOStatement;
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
        self::assertSame('column_name = ?', $postgres->exactValueCondition('column_name', false));
        self::assertSame('column_name COLLATE "C" = ? COLLATE "C"', $postgres->exactValueCondition('column_name', true));
        self::assertSame('column_name COLLATE BINARY = ? COLLATE BINARY', $sqlite->exactValueCondition('column_name', true));
        self::assertSame('BINARY column_name = BINARY ?', $mysql->exactValueCondition('column_name', true));

        $dolt = new DoltProfile();
        self::assertSame('BINARY column_name = BINARY ?', $dolt->exactValueCondition('column_name', true));
        self::assertSame(305, $dolt->maximumSourceVariables());
        self::assertSame(65_504, $dolt->maximumRowBytes());
        self::assertSame('bytes', $dolt->identifierLimitUnit());
        self::assertSame(ServerVersionPolicy::claim('postgresql'), $postgres->serverVersionRange());
        self::assertSame(ServerVersionPolicy::claim('dolt'), $dolt->serverVersionRange());
        self::assertSame(ServerVersionPolicy::claim('sqlite') . '; active version reported at runtime', $sqlite->serverVersionRange());
        self::assertSame(ServerVersionPolicy::claim('mysql') . ' or ' . ServerVersionPolicy::claim('mariadb'), $mysql->serverVersionRange());
    }

    public function testDoltEnvelopeAccepts305VariablesAndRejects306(): void
    {
        $profile = new DoltProfile();
        $profile->assertCanRepresent(305);
        self::assertSame(305, $profile->maximumSourceVariables());

        $this->expectException(UnsupportedOperation::class);
        $this->expectExceptionMessage('at most 305 source variables');
        $profile->assertCanRepresent(306);
    }

    public function testDoltActivePreflightAccepts65504BytesAndRejects65505(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $pdo->method('query')->with('SELECT @@max_allowed_packet')->willReturn($statement);
        $statement->method('fetchColumn')->willReturn('1073741824');
        $profile = new DoltProfile();
        $variables = [['name' => 'payload', 'type' => 'string', 'width' => 65_505]];

        $profile->assertDataset($variables, [[str_repeat('a', 65_504)]], $pdo);

        try {
            $profile->assertDataset($variables, [[str_repeat('a', 65_505)]], $pdo);
            self::fail('Dolt accepted an encoded case beyond its 65,504-byte envelope.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
            self::assertStringContainsString('encoded case payload is 65505 bytes', $exception->getMessage());
            self::assertStringContainsString('limit is 65504 bytes', $exception->getMessage());
        }
    }

    public function testDoltLimitSourcesDoNotClaimInnoDbStorageLimits(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $pdo->expects(self::once())->method('query')->with('SELECT @@max_allowed_packet')->willReturn($statement);
        $statement->expects(self::once())->method('fetchColumn')->willReturn('1073741824');

        $sources = (new DoltProfile())->effectiveLimitSources($pdo);
        self::assertStringNotContainsString('InnoDB', implode("\n", $sources));
        self::assertStringContainsString('305 source variables', $sources['maximum_source_variables']);
        self::assertStringContainsString('65,504-byte', $sources['maximum_row_bytes']);
        self::assertStringContainsString('active @@max_allowed_packet', $sources['maximum_statement_bytes']);
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
    public function testMySqlPreflightRejectsCombinedCasePayloadBeyondActivePacket(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $pdo->expects(self::exactly(2))->method('query')->with('SELECT @@max_allowed_packet')->willReturn($statement);
        $statement->expects(self::exactly(2))->method('fetchColumn')->willReturn('262144');

        $this->expectException(UnsupportedOperation::class);
        $this->expectExceptionMessage('encoded case payload');
        (new MySqlProfile())->assertDataset(
            [
                ['name' => 'one', 'type' => 'string', 'width' => 40_000],
                ['name' => 'two', 'type' => 'string', 'width' => 40_000],
            ],
            [[str_repeat('a', 40_000), str_repeat('b', 40_000)]],
            $pdo,
        );
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
    public function testOperationJournalMigratesLegacyCatalogWithEngineDetails(): void
    {
        if (!in_array("sqlite", PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped("PDO SQLite is not available in this PHP environment.");
        }

        $pdo = new PDO("sqlite::memory:");
        $pdo->exec("CREATE TABLE operation_catalog (operation_id VARCHAR(36) NOT NULL PRIMARY KEY, direction VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, dataset_name TEXT NULL, target_path TEXT NULL, allow_loss TEXT NOT NULL, failure_code VARCHAR(96) NULL, failure_message TEXT NULL)");
        $operationId = (new OperationJournal($pdo))->start("import", null, "legacy.sav", engineDetails: ["package" => "test-engine", "version" => "1.0"]);

        $statement = $pdo->query("SELECT engine_details FROM operation_catalog WHERE operation_id = " . $pdo->quote($operationId));
        self::assertNotFalse($statement);
        $details = $statement->fetchColumn();
        self::assertSame(["package" => "test-engine", "version" => "1.0"], json_decode((string) $details, true, flags: JSON_THROW_ON_ERROR));
    }

}
