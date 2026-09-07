<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\PhpSpssEngine;
use OpenStatSpec\Spss\SpssAdapter;
use OpenStatSpec\Spss\SpssSourceNormalizer;
use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\DoltProfile;
use OpenStatSpec\Sql\MySqlSchema;
use OpenStatSpec\Sql\MySqlWideTableImporter;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Tests\Support\FakeSpssEngine;
use OpenStatSpec\Transformation\Audit\TransformationAuditMigrator;
use PDO;
use PHPUnit\Framework\TestCase;
use SPSS\Sav\Dataset;
use SPSS\Sav\FileMetadata;
use SPSS\Sav\FileTechnicalMetadata;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableFormat;
use SPSS\Sav\VariableMetadata;
use SPSS\Sav\VariableType;

/** Opt-in admin connection; creates and removes only a unique test database and user. */
final class DoltReadOnlyExportTest extends TestCase
{
    public function testSelectOnlyExportPreservesWorkingSetAndHistoryOutsideWriteVersionClaim(): void
    {
        $dsn = getenv('OPENSTATSPEC_DOLT_READ_ONLY_ADMIN_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set OPENSTATSPEC_DOLT_READ_ONLY_ADMIN_DSN for isolated Dolt read-only export coverage.');
        }
        $admin = new PDO($dsn, getenv('OPENSTATSPEC_DOLT_READ_ONLY_ADMIN_USER') ?: 'root', getenv('OPENSTATSPEC_DOLT_READ_ONLY_ADMIN_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $name = 'oss_php_ro_' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(16));
        $target = sys_get_temp_dir() . '/' . $name;
        $createdDatabase = false;
        $createdUser = false;
        try {
            $admin->exec('CREATE DATABASE `' . $name . '`');
            $createdDatabase = true;
            $admin->exec('USE `' . $name . '`');
            // Fixture setup uses low-level catalog writers, not a new public write-version claim.
            CatalogOwnership::ensure($admin);
            (new MySqlSchema($admin, new DoltProfile()))->createCatalog();
            (new NormativeCatalog($admin))->createTables();
            (new TransformationAuditMigrator($admin))->migrate();
            CatalogOwnership::markCurrentVersion($admin);
            $fixture = new Dataset(
                new VariableDictionary([new VariableMetadata('score', VariableType::NUMERIC, 0, new VariableFormat(5, 8, 0), new VariableFormat(5, 8, 0))]),
                [[1.0], [null]],
                new FileMetadata(label: 'original'),
                new FileTechnicalMetadata(sourceFormat: 'sav', encoding: 'UTF-8'),
            );
            (new MySqlWideTableImporter($admin, new DoltProfile()))->import(SpssSourceNormalizer::normalize($fixture), 'fixture', 'fixture.sav');
            $admin->exec("UPDATE dataset SET dataset_label = 'authoritative'");
            $admin->exec("UPDATE variable SET variable_label = 'canonical score'");
            foreach (["CALL DOLT_ADD('-A')", "CALL DOLT_COMMIT('-m', 'Read-only export fixture', '--author', 'OpenStatSpec test <test@example.invalid>')"] as $sql) {
                $statement = $admin->query($sql);
                self::assertInstanceOf(\PDOStatement::class, $statement);
                $statement->closeCursor();
            }
            $admin->exec("CREATE USER '" . $name . "'@'%' IDENTIFIED BY '" . $password . "'");
            $createdUser = true;
            $admin->exec("GRANT SELECT ON `" . $name . "`.* TO '" . $name . "'@'%'");
            $reader = new PDO($dsn, $name, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $reader->exec('USE `' . $name . '`');
            try {
                $reader->exec('UPDATE dataset SET dataset_label = dataset_label WHERE 1 = 0');
                self::fail('The reader unexpectedly has UPDATE privilege.');
            } catch (\PDOException $exception) {
                self::assertStringContainsString('denied', strtolower($exception->getMessage()));
            }
            $adapter = new SpssAdapter($reader);
            $capabilities = $adapter->capabilities();
            self::assertSame('dolt', $capabilities['active_connection']['profile']);
            $before = $this->state($admin);
            foreach (['sav', 'zsav'] as $format) {
                $result = $adapter->export('fixture', $target . '.' . $format);
                self::assertSame(2, $result->caseCount);
                self::assertArrayNotHasKey('operationId', get_object_vars($result));
                $output = (new PhpSpssEngine())->read($target . '.' . $format);
                self::assertSame($fixture->rows(), $output->rows());
                self::assertSame('authoritative', $output->metadata->label);
                self::assertSame('canonical score', $output->variables()[0]->label);
                self::assertSame($format, $output->technicalMetadata->sourceFormat);
            }
            foreach ([['absent', 'sav', DiagnosticCode::InvalidSourceDataset], ['fixture', 'por', DiagnosticCode::UnsupportedSourceFormat]] as [$dataset, $format, $code]) {
                try {
                    $adapter->export($dataset, $target . '.' . $format);
                    self::fail('Invalid export succeeded.');
                } catch (UnsupportedOperation $exception) {
                    self::assertSame($code, $exception->diagnosticCode);
                }
            }
            $engine = new FakeSpssEngine($fixture);
            $engine->writeFailure = new \RuntimeException('writer failed');
            file_put_contents($target . '.sav', 'keep destination');
            try {
                (new SpssAdapter($reader, $engine))->export('fixture', $target . '.sav');
                self::fail('Writer failure was swallowed.');
            } catch (\RuntimeException $exception) {
                self::assertSame('writer failed', $exception->getMessage());
            }
            self::assertSame('keep destination', file_get_contents($target . '.sav'));
            self::assertFileDoesNotExist($engine->lastWrite()['targetPath']);
            if (!$capabilities['active_connection']['claimed_supported']) {
                foreach (['import', 'migrateCatalog'] as $method) {
                    try {
                        if ($method === 'import') {
                            $adapter->import('unused.sav', 'forbidden');
                        } else {
                            $adapter->migrateCatalog();
                        }
                        self::fail('An unclaimed server version permitted a public write.');
                    } catch (UnsupportedOperation $exception) {
                        self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
                    }
                }
            }
            self::assertSame($before, $this->state($admin));
        } finally {
            @unlink($target . '.sav');
            @unlink($target . '.zsav');
            if ($createdUser) {
                $admin->exec("DROP USER '" . $name . "'@'%'");
            }
            if ($createdDatabase) {
                $admin->exec('DROP DATABASE `' . $name . '`');
            }
        }
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function state(PDO $pdo): array
    {
        $state = [];
        foreach ([
            'root' => "SELECT DOLT_HASHOF_DB('WORKING') AS working_root, DOLT_HASHOF_DB('STAGED') AS staged_root",
            'status' => 'SELECT * FROM dolt_status ORDER BY table_name',
            'history' => 'SELECT * FROM dolt_log ORDER BY commit_hash',
            'tables' => 'SHOW TABLES',
        ] as $name => $sql) {
            $statement = $pdo->query($sql);
            self::assertInstanceOf(\PDOStatement::class, $statement);
            $state[$name] = array_values($statement->fetchAll(PDO::FETCH_ASSOC));
        }
        return $state;
    }
}
