<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Tests\Support\SpecificationManifest;
use OpenStatSpec\Transformation\Audit\TransformationAuditMigrator;
use OpenStatSpec\Transformation\Execution\InPlaceApplyRequest;
use OpenStatSpec\Transformation\Execution\InPlaceTransformationExecutor;
use OpenStatSpec\Transformation\Plan\PlanCodec;
use OpenStatSpec\Transformation\Plan\TransformationPlan;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class OfficialInPlaceTransformation01Test extends TestCase
{
    private const DATASET_ID = '66666666-6666-4666-8666-666666666666';

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

    private function planCase(string $id): TransformationPlan
    {
        foreach (SpecificationManifest::load('conformance/transformation-plan-0.1.json')['cases'] as $case) {
            if (is_array($case) && ($case['id'] ?? null) === $id && is_array($case['plan'] ?? null)) {
                return (new PlanCodec())->fromArray($case['plan']);
            }
        }
        throw new \RuntimeException('Missing plan case: ' . $id);
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

    private function scalar(PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        return $statement->fetchColumn();
    }

    /** @return list<mixed> */
    private function column(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        return array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array<string, mixed>> */
    private function rows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
