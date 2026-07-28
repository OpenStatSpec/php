<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\NormativeCatalog;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogOwnershipTest extends TestCase
{
    public function testFreshNamespaceIsClaimedAndIdempotent(): void
    {
        $pdo = $this->sqlite();
        CatalogOwnership::ensure($pdo);
        CatalogOwnership::ensure($pdo);

        self::assertSame(
            [['openstatspec-strict-wide-table-v1', 1]],
            $this->query($pdo, 'SELECT contract_id, schema_version FROM catalog_identity')->fetchAll(PDO::FETCH_NUM),
        );
        self::assertSame('main', CatalogOwnership::binding($pdo)['name']);
    }

    public function testIdentityContractCheckRejectsForeignContract(): void
    {
        $pdo = $this->sqlite();
        CatalogOwnership::ensure($pdo);
        $pdo->exec('DELETE FROM catalog_identity');

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO catalog_identity (catalog_identity_key, contract_id, schema_version, created_at) VALUES (1, 'foreign-contract', 1, '2026-07-28 00:00:00')");
    }

    public function testFreshClaimRejectsUnrelatedApplicationTable(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE application_data (id INTEGER PRIMARY KEY)');
        $binding = CatalogOwnership::binding($pdo);
        self::assertFalse($binding['exclusive_namespace_verified']);
        self::assertSame('unverified', $binding['verification_status']);

        try {
            CatalogOwnership::ensure($pdo);
            self::fail('A non-empty namespace was claimed as fresh.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::CatalogNamespaceCollision, $exception->diagnosticCode);
        }

        self::assertSame([], $this->query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'catalog_identity'")->fetchAll(PDO::FETCH_COLUMN));
    }

    #[DataProvider('foreignViewNames')]
    public function testFreshClaimRejectsForeignViewBeforeCreatingIdentity(string $viewName): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE VIEW ' . $viewName . ' AS SELECT 1 AS application_id');

        try {
            CatalogOwnership::ensure($pdo);
            self::fail('A foreign view was accepted as an OpenStatSpec table or empty namespace.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::CatalogNamespaceCollision, $exception->diagnosticCode);
        }

        self::assertSame([], $this->query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'catalog_identity'")->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame([$viewName], $this->query($pdo, "SELECT name FROM sqlite_master WHERE type = 'view'")->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return iterable<string, array{string}> */
    public static function foreignViewNames(): iterable
    {
        yield 'unrelated name' => ['application_view'];
        yield 'catalog table name' => ['dataset'];
    }

    public function testPostgreSqlInventoryIncludesForeignBehaviorObjectsAndConstraintOwnership(): void
    {
        $pdo = $this->createMock(PDO::class);
        $schemaCount = $this->createMock(PDOStatement::class);
        $inventory = $this->createMock(PDOStatement::class);
        $schemaCount->method('fetchColumn')->willReturn(1);
        $inventory->method('fetchAll')->willReturn([
            ['name' => 'mood', 'kind' => 'enum_type', 'parent_name' => null, 'constraint_owned' => false],
            ['name' => 'mutate', 'kind' => 'function', 'parent_name' => null, 'constraint_owned' => false],
            ['name' => 'audit_trigger', 'kind' => 'trigger', 'parent_name' => 'dataset', 'constraint_owned' => false],
        ]);
        $inventorySql = '';
        $pdo->method('getAttribute')->willReturn('pgsql');
        $pdo->expects(self::exactly(2))->method('query')->willReturnCallback(
            static function (string $sql) use ($schemaCount, $inventory, &$inventorySql): PDOStatement {
                if (str_contains($sql, 'current_schemas(false)')) {
                    return $schemaCount;
                }
                $inventorySql = $sql;
                return $inventory;
            },
        );

        $objects = $this->namespaceObjects($pdo);

        self::assertSame(['enum_type', 'function', 'trigger'], array_column($objects, 'kind'));
        self::assertStringContainsString('FROM pg_type', $inventorySql);
        self::assertStringContainsString('FROM pg_proc', $inventorySql);
        self::assertStringContainsString('FROM pg_trigger', $inventorySql);
        self::assertStringContainsString('FROM pg_rewrite', $inventorySql);
        self::assertStringContainsString('FROM pg_policy', $inventorySql);
        self::assertStringContainsString('pg_constraint', $inventorySql);
        self::assertStringContainsString("user_type.typtype IN ('b', 'c', 'd', 'e', 'r', 'm')", $inventorySql);
        self::assertStringContainsString("WHEN user_type.typtype = 'm' THEN 'multirange_type'", $inventorySql);
    }

    public function testMySqlInventoryIncludesTriggersRoutinesAndEvents(): void
    {
        $pdo = $this->createMock(PDO::class);
        $inventory = $this->createMock(PDOStatement::class);
        $inventory->method('fetchAll')->willReturn([
            ['name' => 'audit_trigger', 'kind' => 'trigger', 'parent_name' => 'dataset', 'constraint_owned' => 0],
            ['name' => 'refresh_data', 'kind' => 'procedure', 'parent_name' => null, 'constraint_owned' => 0],
            ['name' => 'nightly', 'kind' => 'event', 'parent_name' => null, 'constraint_owned' => 0],
        ]);
        $inventorySql = '';
        $pdo->method('getAttribute')->willReturn('mysql');
        $pdo->expects(self::once())->method('query')->willReturnCallback(
            static function (string $sql) use ($inventory, &$inventorySql): PDOStatement {
                $inventorySql = $sql;
                return $inventory;
            },
        );

        $objects = $this->namespaceObjects($pdo);

        self::assertSame(['trigger', 'procedure', 'event'], array_column($objects, 'kind'));
        self::assertStringContainsString('information_schema.triggers', $inventorySql);
        self::assertStringContainsString('information_schema.routines', $inventorySql);
        self::assertStringContainsString('information_schema.events', $inventorySql);
        self::assertStringContainsString('information_schema.statistics', $inventorySql);
        self::assertStringContainsString('information_schema.table_constraints', $inventorySql);
    }

    public function testPostgreSqlIndexAllowanceIsLimitedToConstraintsOrRegisteredPhysicalTables(): void
    {
        $objects = [
            ['name' => 'dataset_pkey', 'kind' => 'index', 'parent_name' => 'dataset', 'constraint_owned' => true],
            ['name' => 'foreign_catalog_index', 'kind' => 'index', 'parent_name' => 'dataset', 'constraint_owned' => false],
            ['name' => 'physical_lookup', 'kind' => 'index', 'parent_name' => 'dataset_registered', 'constraint_owned' => false],
            ['name' => 'dataset', 'kind' => 'relation_row_type', 'parent_name' => 'dataset', 'constraint_owned' => false],
            ['name' => 'foreign_view', 'kind' => 'view_row_type', 'parent_name' => 'dataset', 'constraint_owned' => false],
        ];

        $unexpected = $this->unexpectedNamespaceObjects($objects, ['dataset', 'dataset_registered'], ['dataset_registered']);

        self::assertSame(['foreign_catalog_index', 'foreign_view'], array_column($unexpected, 'name'));
    }

    /**
     * @return list<array{name: string, kind: string, parent_name: string|null, constraint_owned: bool}>
     */
    private function namespaceObjects(PDO $pdo): array
    {
        $method = new \ReflectionMethod(CatalogOwnership::class, 'namespaceObjects');
        $objects = $method->invoke(null, $pdo);
        self::assertIsArray($objects);
        /** @var list<array{name: string, kind: string, parent_name: string|null, constraint_owned: bool}> $objects */
        return $objects;
    }

    /**
     * @param list<array{name: string, kind: string, parent_name: string|null, constraint_owned: bool}> $objects
     * @param list<string>                                                                               $allowedTables
     * @param list<string>                                                                               $registeredTables
     * @return list<array{name: string, kind: string, parent_name: string|null, constraint_owned: bool}>
     */
    private function unexpectedNamespaceObjects(array $objects, array $allowedTables, array $registeredTables): array
    {
        $method = new \ReflectionMethod(CatalogOwnership::class, 'unexpectedNamespaceObjects');
        $unexpected = $method->invoke(null, $objects, $allowedTables, $registeredTables);
        self::assertIsArray($unexpected);
        /** @var list<array{name: string, kind: string, parent_name: string|null, constraint_owned: bool}> $unexpected */
        return $unexpected;
    }

    public function testCurrentIdentityRejectsLaterUnrelatedApplicationTable(): void
    {
        $pdo = $this->sqlite();
        CatalogOwnership::ensure($pdo);
        (new NormativeCatalog($pdo))->createTables();
        CatalogOwnership::markCurrentVersion($pdo);
        $pdo->exec('CREATE TABLE application_data (id INTEGER PRIMARY KEY)');

        try {
            CatalogOwnership::ensure($pdo);
            self::fail('An unrelated table was accepted in an owned namespace.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::CatalogNamespaceCollision, $exception->diagnosticCode);
        }
    }

    public function testRegisteredPhysicalDataTableIsAllowed(): void
    {
        $pdo = $this->sqlite();
        CatalogOwnership::ensure($pdo);
        (new NormativeCatalog($pdo))->createTables();
        $pdo->exec('CREATE TABLE dataset_registered (case_ordinal INTEGER PRIMARY KEY)');
        $pdo->exec("INSERT INTO dataset (dataset_id, spec_version, source_format, physical_table_name, dataset_name, source_case_count, imported_at) VALUES ('registered', '1.0', 'sav', 'dataset_registered', 'registered', 0, '2026-07-28 00:00:00')");
        CatalogOwnership::markCurrentVersion($pdo);

        CatalogOwnership::ensure($pdo);

        self::assertSame(3, (int) $this->query($pdo, 'SELECT schema_version FROM catalog_identity')->fetchColumn());
    }

    public function testOlderIdentityVersionIsAcceptedWithoutPrematureUpgrade(): void
    {
        $pdo = $this->sqlite();
        CatalogOwnership::ensure($pdo);
        $pdo->exec('UPDATE catalog_identity SET schema_version = 1');

        CatalogOwnership::ensure($pdo);

        self::assertSame(1, (int) $this->query($pdo, 'SELECT schema_version FROM catalog_identity')->fetchColumn());
    }

    public function testFutureIdentityVersionFailsClosed(): void
    {
        $pdo = $this->sqlite();
        CatalogOwnership::ensure($pdo);
        $pdo->exec('UPDATE catalog_identity SET schema_version = 4');

        try {
            CatalogOwnership::ensure($pdo);
            self::fail('An unknown future catalogue schema version was accepted.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::CatalogNamespaceCollision, $exception->diagnosticCode);
        }

        self::assertSame(4, (int) $this->query($pdo, 'SELECT schema_version FROM catalog_identity')->fetchColumn());
    }

    public function testForeignCatalogNameFailsBeforeOwnershipObjectsAreCreated(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE dataset (application_id INTEGER PRIMARY KEY)');

        try {
            CatalogOwnership::ensure($pdo);
            self::fail('A foreign dataset table was accepted as OpenStatSpec catalogue state.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::CatalogNamespaceCollision, $exception->diagnosticCode);
        }

        self::assertSame([], $this->query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('catalog_identity', 'openstatspec_schema_migration')")->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(['application_id'], $this->query($pdo, 'PRAGMA table_info(dataset)')->fetchAll(PDO::FETCH_COLUMN, 1));
    }

    public function testMarkerOnlyNamespaceIsNotAcceptedAsOwnedCatalog(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE openstatspec_schema_migration (version INTEGER PRIMARY KEY, applied_at TIMESTAMP NOT NULL)');
        $pdo->exec("INSERT INTO openstatspec_schema_migration VALUES (1, '2026-07-28 00:00:00')");

        try {
            CatalogOwnership::ensure($pdo);
            self::fail('A migration marker without a complete catalogue was accepted.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::CatalogNamespaceCollision, $exception->diagnosticCode);
        }

        self::assertSame([], $this->query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'catalog_identity'")->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return iterable<string, array{list<int>}> */
    public static function invalidMigrationHistories(): iterable
    {
        yield 'empty' => [[]];
        yield 'zero' => [[0]];
        yield 'future' => [[99]];
        yield 'gap' => [[1, 3]];
    }

    /** @param list<int> $versions */
    #[DataProvider('invalidMigrationHistories')]
    public function testInvalidMigrationHistoryFailsClosed(array $versions): void
    {
        $pdo = $this->sqlite();
        (new NormativeCatalog($pdo))->createTables();
        $pdo->exec('DROP TABLE catalog_identity');
        $pdo->exec('DELETE FROM openstatspec_schema_migration');
        $insert = $pdo->prepare('INSERT INTO openstatspec_schema_migration (version, applied_at) VALUES (?, ?)');
        foreach ($versions as $version) {
            $insert->execute([$version, '2026-07-28 00:00:00']);
        }

        try {
            CatalogOwnership::ensure($pdo);
            self::fail('An invalid migration history was accepted.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::CatalogNamespaceCollision, $exception->diagnosticCode);
        }

        self::assertSame([], $this->query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'catalog_identity'")->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testMigrationMarkerRejectsForeignCanonicalNameCollision(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE openstatspec_schema_migration (version INTEGER PRIMARY KEY, applied_at TIMESTAMP NOT NULL)');
        $pdo->exec("INSERT INTO openstatspec_schema_migration VALUES (1, '2026-07-28 00:00:00')");
        $pdo->exec('CREATE TABLE dataset (application_id INTEGER PRIMARY KEY)');

        try {
            CatalogOwnership::ensure($pdo);
            self::fail('A migration marker incorrectly claimed a foreign dataset table.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::CatalogNamespaceCollision, $exception->diagnosticCode);
        }

        self::assertSame([], $this->query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'catalog_identity'")->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(['application_id'], $this->query($pdo, 'PRAGMA table_info(dataset)')->fetchAll(PDO::FETCH_COLUMN, 1));
    }

    public function testReleasedCanonicalCatalogCanBeReclaimedFromMigrationMarker(): void
    {
        $pdo = $this->sqlite();
        (new NormativeCatalog($pdo))->createTables();
        $pdo->exec('DROP TABLE catalog_identity');

        CatalogOwnership::ensure($pdo);

        self::assertSame('openstatspec-strict-wide-table-v1', $this->query($pdo, 'SELECT contract_id FROM catalog_identity')->fetchColumn());
        self::assertSame(3, (int) $this->query($pdo, 'SELECT schema_version FROM catalog_identity')->fetchColumn());
    }

    public function testIncompleteLegacyCatalogCannotClaimTheNamespace(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE datasets (dataset_name TEXT NOT NULL PRIMARY KEY, table_name TEXT NOT NULL UNIQUE)');

        try {
            CatalogOwnership::ensure($pdo);
            self::fail('An incomplete legacy catalogue was accepted as owned OpenStatSpec state.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::CatalogNamespaceCollision, $exception->diagnosticCode);
        }

        self::assertSame([], $this->query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('catalog_identity', 'openstatspec_schema_migration')")->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testMalformedLegacyJournalCannotClaimTheNamespace(): void
    {
        $pdo = $this->sqlite();
        $pdo->exec('CREATE TABLE operation_catalog (operation_id TEXT PRIMARY KEY)');

        try {
            CatalogOwnership::ensure($pdo);
            self::fail('A malformed legacy operation journal was accepted as owned OpenStatSpec state.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::CatalogNamespaceCollision, $exception->diagnosticCode);
        }

        self::assertSame([], $this->query($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('catalog_identity', 'openstatspec_schema_migration')")->fetchAll(PDO::FETCH_COLUMN));
    }

    private function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        return $statement;
    }

    private function sqlite(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available.');
        }
        return new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
