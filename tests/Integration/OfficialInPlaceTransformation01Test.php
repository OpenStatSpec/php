<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Tests\Support\SpecificationManifest;
use OpenStatSpec\Transformation\Audit\TransformationAuditMigrator;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Execution\DoltEvidence;
use OpenStatSpec\Transformation\Execution\DoltEvidenceReader;
use OpenStatSpec\Transformation\Execution\DoltGuard;
use OpenStatSpec\Transformation\Execution\InPlaceApplyRequest;
use OpenStatSpec\Transformation\Execution\InPlaceTransformationExecutor;
use OpenStatSpec\Transformation\Plan\PlanCodec;
use OpenStatSpec\Transformation\Plan\TransformationPlan;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfficialInPlaceTransformation01Test extends TestCase
{
    private const DATASET_ID = '66666666-6666-4666-8666-666666666666';

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function officialDoltContextFailures(): iterable
    {
        foreach (SpecificationManifest::load('conformance/in-place-transformation-0.1.json')['cases'] as $case) {
            if (is_array($case) && in_array($case['id'] ?? null, [
                'reject-dolt-branch-mismatch',
                'reject-dolt-head-mismatch',
                'reject-dolt-dirty-working-set',
            ], true)) {
                yield (string) $case['id'] => [$case];
            }
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('officialDoltContextFailures')]
    public function testOfficialDoltContextFailuresOccurBeforeMutation(array $case): void
    {
        $expected = $case['expected_context'];
        $observed = $case['observed_context'];
        self::assertIsArray($expected);
        self::assertIsArray($observed);
        $guard = new DoltGuard(new class ($observed) implements DoltEvidenceReader {
            /** @param array<string, mixed> $observed */
            public function __construct(private readonly array $observed) {}

            public function read(): DoltEvidence
            {
                return new DoltEvidence(
                    (string) $this->observed['branch'],
                    (string) $this->observed['head'],
                    $this->observed['working_set_clean'] === true ? [] : ['data_survey'],
                );
            }
        });
        $request = new InPlaceApplyRequest(
            $this->planCase('string-value-label-replacement'),
            'parent',
            self::DATASET_ID,
            str_repeat('a', 64),
            'conformance-runner',
            (string) $expected['branch'],
            (string) $expected['head'],
        );

        try {
            $guard->beforeExecution($request);
            self::fail('The official Dolt context mismatch was accepted.');
        } catch (TransformationFailure $failure) {
            self::assertSame($case['expected_error'], $failure->diagnosticCode());
        }
        self::assertFalse($case['mutation_started']);
    }

    public function testOfficialMysqlCreateTargetCaseFailsBeforeMutationWhenConfigured(): void
    {
        $case = $this->bindingCase('reject-mysql-nontransactional-create-target');
        $pdo = $this->mysql();
        $connection = new Connection($pdo);
        self::assertSame('mysql', $connection->profileName);
        $table = 'data_plan01_task9';
        (new NormativeCatalog($pdo))->createTables();
        (new TransformationAuditMigrator($pdo))->migrate();
        CatalogOwnership::markCurrentVersion($pdo);
        self::assertSame(0, (int) $this->scalar(
            $pdo,
            'SELECT COUNT(*) FROM dataset WHERE dataset_id = ? OR physical_table_name = ?',
            [self::DATASET_ID, $table],
        ));
        self::assertSame(0, (int) $this->scalar(
            $pdo,
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table],
        ));

        try {
            $pdo->exec('CREATE TABLE ' . $connection->profile->quoteIdentifier($table)
                . ' (`__case_ordinal` BIGINT NOT NULL PRIMARY KEY, `q1` DOUBLE NULL)');
            $pdo->prepare(
                'INSERT INTO dataset (dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, dataset_name, source_case_count, imported_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            )->execute([self::DATASET_ID, '1.0', 'fixture', null, $table, 'Task 9 official 0.1', 2, '2026-08-17 00:00:00']);
            $pdo->prepare(
                'INSERT INTO variable (variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind) VALUES (?, ?, ?, ?, ?, ?)',
            )->execute(['99999999-9999-4999-8999-999999999999', self::DATASET_ID, 1, 'q1', 'q1', 'numeric']);
            $pdo->exec('INSERT INTO ' . $connection->profile->quoteIdentifier($table)
                . ' (`__case_ordinal`, `q1`) VALUES (1, 1), (2, NULL)');
            $before = $this->mysqlFailureSnapshot($pdo, $connection, $table);
            $request = new InPlaceApplyRequest(
                $this->planCase('numeric-recode-and-declared-labels'),
                'parent',
                self::DATASET_ID,
                hash('sha256', (string) $case['source_text']),
                'conformance-runner',
            );

            try {
                (new InPlaceTransformationExecutor($connection))->execute($request);
                self::fail('MySQL accepted an official 0.1 create-target plan.');
            } catch (TransformationFailure $failure) {
                self::assertSame($case['expected_error'], $failure->diagnosticCode());
            }

            self::assertFalse($case['mutation_started']);
            self::assertFalse($pdo->inTransaction());
            self::assertSame($before, $this->mysqlFailureSnapshot($pdo, $connection, $table));
        } finally {
            $pdo->prepare('DELETE FROM transformation_apply WHERE dataset_id = ?')->execute([self::DATASET_ID]);
            $pdo->prepare('DELETE FROM variable WHERE dataset_id = ?')->execute([self::DATASET_ID]);
            $pdo->prepare('DELETE FROM dataset WHERE dataset_id = ?')->execute([self::DATASET_ID]);
            $pdo->exec('DROP TABLE IF EXISTS ' . $connection->profile->quoteIdentifier($table));
        }
    }

    public function testSqliteNumericRecodeUsesFirstMatchInclusiveRangeAndSystemMissing(): void
    {
        $pdo = $this->fixture();
        $connection = new Connection($pdo);
        $plan = $this->planCase('numeric-recode-and-declared-labels');
        $request = new InPlaceApplyRequest(
            $plan,
            'parent',
            self::DATASET_ID,
            hash('sha256', 'RECODE q1.'),
            'conformance-runner',
        );
        $tablesBefore = $this->tables($pdo);

        $result = (new InPlaceTransformationExecutor($connection))->execute($request);

        self::assertSame('075571b4f84b0f60f289aebb6ec990ad61d34a96b46655802ea8f8bc07a1a405', $result->planHash());
        self::assertSame($tablesBefore, $this->tables($pdo));
        self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM dataset'));
        self::assertSame(
            [0.0, 0.0, 1.0, 1.0, 1.0, null, null],
            array_map(
                static fn(mixed $value): ?float => $value === null ? null : (float) $value,
                $this->column($pdo, 'SELECT q1_binary FROM data_plan01 ORDER BY __case_ordinal'),
            ),
        );
        self::assertSame('Positive response', $this->scalar(
            $pdo,
            "SELECT variable_label FROM variable WHERE dataset_id = '" . self::DATASET_ID . "' AND source_name = 'q1_binary'",
        ));
        self::assertSame(
            [[0.0, 'No'], [1.0, 'Yes']],
            array_map(
                static fn(array $row): array => [(float) $row['numeric_code'], (string) $row['label']],
                $this->rows($pdo, 'SELECT label.numeric_code, label.label FROM value_label label ORDER BY label.ordinal'),
            ),
        );
        self::assertSame(
            ['openstatspec-in-place-transformation-v0.1', $result->planHash(), 3],
            $this->normalizedAudit($pdo),
        );
    }

    public function testSqliteStringValueLabelsAreReplacedExactlyWithoutChangingOtherMetadata(): void
    {
        $pdo = $this->fixture();
        $pdo->exec("ALTER TABLE data_plan01 ADD COLUMN color TEXT NOT NULL DEFAULT ''");
        $pdo->prepare(
            'INSERT INTO variable (variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind, declared_string_width, variable_label, measurement_level) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute(['77777777-7777-4777-8777-777777777777', self::DATASET_ID, 2, 'color', 'color', 'string', 1, 'Colour', 'nominal']);
        $pdo->exec("UPDATE data_plan01 SET color = CASE WHEN __case_ordinal % 2 = 0 THEN 'B' ELSE 'R' END");
        $plan = $this->planCase('string-value-label-replacement');
        $request = new InPlaceApplyRequest($plan, 'parent', self::DATASET_ID, hash('sha256', 'VALUE LABELS color.'), 'conformance-runner');

        (new InPlaceTransformationExecutor(new Connection($pdo)))->execute($request);

        self::assertSame(
            [['R', 'Punane'], ['B', 'Sinine']],
            array_map(
                static fn(array $row): array => [(string) $row['string_code'], (string) $row['label']],
                $this->rows($pdo, 'SELECT label.string_code, label.label FROM value_label label ORDER BY label.ordinal'),
            ),
        );
        self::assertSame(
            ['variable_label' => 'Colour', 'measurement_level' => 'nominal'],
            $this->rows($pdo, "SELECT variable_label, measurement_level FROM variable WHERE source_name = 'color'")[0],
        );
    }

    private function fixture(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available.');
        }
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => false]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        (new NormativeCatalog($pdo))->createTables();
        (new TransformationAuditMigrator($pdo))->migrate();
        CatalogOwnership::markCurrentVersion($pdo);
        $pdo->exec('CREATE TABLE data_plan01 (__case_ordinal INTEGER NOT NULL PRIMARY KEY, q1 REAL NULL)');
        $pdo->prepare(
            'INSERT INTO dataset (dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, dataset_name, source_case_count, imported_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([self::DATASET_ID, '1.0', 'fixture', null, 'data_plan01', 'Plan 0.1 fixture', 7, '2026-08-17 00:00:00']);
        $pdo->prepare(
            'INSERT INTO variable (variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind) VALUES (?, ?, ?, ?, ?, ?)',
        )->execute(['88888888-8888-4888-8888-888888888888', self::DATASET_ID, 1, 'q1', 'q1', 'numeric']);
        $insert = $pdo->prepare('INSERT INTO data_plan01 (__case_ordinal, q1) VALUES (?, ?)');
        foreach ([[1, 1.0], [2, 2.0], [3, 3.0], [4, 4.0], [5, 5.0], [6, 6.0], [7, null]] as $row) {
            $insert->execute($row);
        }
        return $pdo;
    }

    private function mysql(): PDO
    {
        $dsn = getenv('OPENSTATSPEC_MYSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('OPENSTATSPEC_MYSQL_DSN is not configured.');
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO MySQL is not available.');
        }
        $user = getenv('OPENSTATSPEC_MYSQL_USER');
        $password = getenv('OPENSTATSPEC_MYSQL_PASSWORD');

        return new PDO(
            $dsn,
            is_string($user) ? $user : null,
            is_string($password) ? $password : null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => false],
        );
    }

    private function planCase(string $id): TransformationPlan
    {
        foreach (SpecificationManifest::load('conformance/transformation-plan-0.1.json')['cases'] as $case) {
            if (is_array($case) && ($case['id'] ?? null) === $id && is_array($case['plan'] ?? null)) {
                return (new PlanCodec())->fromArray($case['plan']);
            }
        }
        throw new \RuntimeException('Missing plan case: ' . $id);
    }

    /** @return array<string, mixed> */
    private function bindingCase(string $id): array
    {
        foreach (SpecificationManifest::load('conformance/in-place-transformation-0.1.json')['cases'] as $case) {
            if (is_array($case) && ($case['id'] ?? null) === $id) {
                return $case;
            }
        }

        throw new \RuntimeException('Missing in-place 0.1 case: ' . $id);
    }

    /** @return array<string, mixed> */
    private function mysqlFailureSnapshot(PDO $pdo, Connection $connection, string $table): array
    {
        return [
            'dataset_count' => (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM dataset'),
            'persistent_data_table_count' => (int) $this->scalar($pdo, 'SELECT COUNT(DISTINCT physical_table_name) FROM dataset'),
            'dataset' => $this->rows($pdo, 'SELECT * FROM dataset WHERE dataset_id = ?', [self::DATASET_ID]),
            'variables' => $this->rows($pdo, 'SELECT * FROM variable WHERE dataset_id = ? ORDER BY source_ordinal', [self::DATASET_ID]),
            'audit' => $this->rows($pdo, 'SELECT * FROM transformation_apply WHERE dataset_id = ? ORDER BY apply_id', [self::DATASET_ID]),
            'tables' => $this->column($pdo, 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'),
            'rows' => $this->rows(
                $pdo,
                'SELECT * FROM ' . $connection->profile->quoteIdentifier($table)
                    . ' ORDER BY ' . $connection->profile->quoteIdentifier('__case_ordinal'),
            ),
        ];
    }

    /** @return list<string> */
    private function tables(PDO $pdo): array
    {
        return array_map('strval', $this->column($pdo, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"));
    }

    /** @return array{string, string, int} */
    private function normalizedAudit(PDO $pdo): array
    {
        $row = $this->rows($pdo, 'SELECT contract_id, plan_hash, operation_count FROM transformation_apply')[0];
        return [(string) $row['contract_id'], (string) $row['plan_hash'], (int) $row['operation_count']];
    }

    /** @param list<mixed> $parameters */
    private function scalar(PDO $pdo, string $sql, array $parameters = []): mixed
    {
        $statement = $pdo->prepare($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }

    /** @return list<mixed> */
    private function column(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        return array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute($parameters);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
