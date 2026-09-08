<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\CanonicalWideTableExporter;
use OpenStatSpec\Sql\PostgreSqlWideTableExporter;
use OpenStatSpec\Tests\Support\ExportCountingPdo;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Sql\PostgreSqlWideTableImporter;
use OpenStatSpec\Spss\PhpSpssEngine;
use OpenStatSpec\Transformation\Audit\TransformationAuditMigrator;
use OpenStatSpec\Transformation\Execution\InPlaceApplyRequest;
use OpenStatSpec\Transformation\Execution\InPlaceTransformationExecutor;
use OpenStatSpec\Transformation\Plan\Expression\LiteralOperand;
use OpenStatSpec\Transformation\Plan\Operation\AssignOperation;
use OpenStatSpec\Transformation\Plan\PlanContract;
use OpenStatSpec\Transformation\Plan\TargetMode;
use OpenStatSpec\Transformation\Plan\TransformationPlan;
use OpenStatSpec\Transformation\Plan\Value\Binary64Value;
use OpenStatSpec\Spss\SpssAdapter;
use OpenStatSpec\Tests\Support\FakeSpssEngine;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SPSS\Sav\Alignment;
use SPSS\Sav\Dataset;
use SPSS\Sav\FileAttribute;
use SPSS\Sav\FileMetadata;
use SPSS\Sav\FileTechnicalMetadata;
use SPSS\Sav\Measure;
use SPSS\Sav\MissingValues;
use SPSS\Sav\MultipleResponseCategoryLabels;
use SPSS\Sav\MultipleResponseLabelSource;
use SPSS\Sav\MultipleResponseSet;
use SPSS\Sav\MultipleResponseSetType;
use SPSS\Sav\ValueLabel;
use SPSS\Sav\ValueLabelSet;
use SPSS\Sav\VariableAttribute;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableFormat;
use SPSS\Sav\VariableMetadata;
use SPSS\Sav\VariableRole;
use SPSS\Sav\VariableSet;
use SPSS\Sav\VariableType;

/**
 * Runs only against an explicitly configured PostgreSQL instance.
 *
 * GitHub Actions supplies the service. Developers can opt in locally with:
 * OPENSTATSPEC_PG_DSN, OPENSTATSPEC_PG_USER, OPENSTATSPEC_PG_PASSWORD.
 */
final class PostgreSqlSpssRoundTripTest extends TestCase
{
    use VariableCatalogAssertions;
    public function testRealEngineRoundTripsSavAndZsavThroughPostgreSql(): void
    {
        $pdo = $this->postgres();
        self::assertInstanceOf(ExportCountingPdo::class, $pdo);
        $engine = new PhpSpssEngine();

        foreach (['sav' => ['$FL2', 1], 'zsav' => ['$FL3', 2]] as $format => [$header, $compression]) {
            $token = bin2hex(random_bytes(6));
            $datasetName = 'postgres integration ' . $format . ' ' . $token;
            $sourcePath = sys_get_temp_dir() . '/openstatspec-pg-source-' . $token . '.' . $format;
            $targetPath = sys_get_temp_dir() . '/openstatspec-pg-target-' . $token . '.' . $format;
            $tableName = null;

            try {
                $fixture = $this->fixture($format);
                $engine->write($sourcePath, $fixture);
                self::assertSame($header, $this->fileHeader($sourcePath));

                $adapter = new SpssAdapter($pdo, $engine);
                $adapter->import($sourcePath, $datasetName);
                $tableName = $this->tableName($pdo, $datasetName);

                self::assertMatchesRegularExpression('/^dataset_postgres_integration_/', $tableName);
                $caseCount = $pdo->query('SELECT COUNT(*) FROM ' . $this->quote($tableName));
                if ($caseCount === false) {
                    throw new RuntimeException('Could not count imported PostgreSQL cases.');
                }
                self::assertSame(2, (int) $caseCount->fetchColumn());
                $this->assertVariableCatalog(
                    $this->rows($pdo, 'SELECT ordinal, source_name, storage_kind FROM variables WHERE dataset_name = ? ORDER BY ordinal', [$datasetName]),
                );
                self::assertSame(2, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM documents WHERE dataset_name = ?', [$datasetName]));
                self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM value_labels WHERE dataset_name = ?', [$datasetName]));
                self::assertSame(4, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM missing_rule_values WHERE dataset_name = ?', [$datasetName]));
                self::assertSame(3, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM variable_roles WHERE dataset_name = ?', [$datasetName]));
                self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM file_attributes WHERE dataset_name = ?', [$datasetName]));
                self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM variable_sets WHERE dataset_name = ?', [$datasetName]));
                self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM multiple_response_sets WHERE dataset_name = ?', [$datasetName]));
                self::assertSame(1, (int) $this->scalar($pdo, 'SELECT variable_ordinal FROM dataset_weight_variables WHERE dataset_name = ?', [$datasetName]));

                $executionCounts = [];
                foreach ([CanonicalWideTableExporter::class, PostgreSqlWideTableExporter::class] as $exporterClass) {
                    $pdo->executions = [];
                    $direct = (new $exporterClass($pdo))->export($datasetName, $format);
                    $executionCounts[] = count($pdo->captured());
                    $pdo->executions = null;
                    self::assertSame([], $direct['diagnostics']);
                    self::assertSame($fixture->rows(), $direct['dataset']->rows());
                    self::assertEquals($fixture->metadata, $direct['dataset']->metadata);
                }
                $result = $adapter->export($datasetName, $targetPath);
                self::assertSame([], $result->diagnostics);
                self::assertSame(2, $result->caseCount);
                self::assertSame($header, $this->fileHeader($targetPath));

                $roundTrip = $engine->read($targetPath);
                self::assertSame($fixture->rows(), $roundTrip->rows());
                self::assertSame($format, $roundTrip->technicalMetadata->sourceFormat);
                self::assertSame($compression, $roundTrip->technicalMetadata->compression);
                self::assertSame('PostgreSQL integration fixture', $roundTrip->metadata->label);
                self::assertSame('Score', $roundTrip->metadata->weightVariableName);
                self::assertSame(['First document line', 'Second document line'], $roundTrip->metadata->documents());
                self::assertCount(3, $roundTrip->variables());
                self::assertSame('Score', $roundTrip->variables()[0]->name);
                self::assertSame('Result score', $roundTrip->variables()[0]->label);
                self::assertEquals([new ValueLabel(7.5, 'Seven and a half')], $roundTrip->variables()[0]->valueLabels->labels());
                self::assertEquals(MissingValues::rangeAndValue(-99.0, -1.0, 999.0), $roundTrip->variables()[0]->missingValues);
                self::assertSame(Measure::SCALE, $roundTrip->variables()[0]->measure);
                self::assertSame(Alignment::RIGHT, $roundTrip->variables()[0]->alignment);
                self::assertSame(12, $roundTrip->variables()[0]->columns);
                self::assertSame(VariableRole::TARGET, $roundTrip->variables()[0]->role);
                self::assertEquals([new VariableAttribute('Score', 'Origin', ['integration'])], $roundTrip->variables()[0]->attributes());
                self::assertEquals(MissingValues::discrete('MISSING'), $roundTrip->variables()[1]->missingValues);
                self::assertSame(400, $roundTrip->variables()[2]->width);
                self::assertSame(340, strlen((string) $roundTrip->rows()[0][2]));
                self::assertEquals([new FileAttribute('Source', ['PostgreSQL integration'])], $roundTrip->metadata->attributes());
                self::assertEquals([new VariableSet('Core', ['Score', 'Reason'])], $roundTrip->metadata->variableSets());
                self::assertCount(1, $roundTrip->metadata->multipleResponseSets());
                self::assertSame(MultipleResponseSetType::DICHOTOMY, $roundTrip->metadata->multipleResponseSets()[0]->type);
                self::assertSame(['Reason'], $roundTrip->metadata->multipleResponseSets()[0]->variableNames());
                self::assertLessThanOrEqual(14, $executionCounts[0], 'Canonical export SQL executions');
                self::assertLessThanOrEqual(18, $executionCounts[1], 'Legacy export SQL executions');
            } finally {
                $this->cleanup($pdo, $datasetName, $tableName);
                @unlink($sourcePath);
                @unlink($targetPath);
            }
        }
    }

    public function testDeleteThenCreateAtThePostgreSqlPhysicalColumnLimitFailsBeforeMutation(): void
    {
        $pdo = $this->postgres();
        $datasetId = '018f47f2-8b6a-7c3d-9e1f-123456789abc';
        $tableName = 'transform_slots_' . bin2hex(random_bytes(6));

        try {
            (new NormativeCatalog($pdo))->createTables();
            (new TransformationAuditMigrator($pdo))->migrate();
            CatalogOwnership::markCurrentVersion($pdo);
            $columns = ['__case_ordinal BIGINT NOT NULL PRIMARY KEY'];
            for ($ordinal = 1; $ordinal <= 1599; ++$ordinal) {
                $columns[] = 'v' . $ordinal . ' DOUBLE PRECISION NULL';
            }
            $pdo->exec('CREATE TABLE ' . $this->quote($tableName) . ' (' . implode(', ', $columns) . ')');
            $pdo->prepare(
                'INSERT INTO dataset '
                . '(dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, dataset_name, source_case_count, imported_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
            )->execute([$datasetId, '1.0', 'fixture', null, $tableName, 'PostgreSQL slot fixture', 0]);
            $insert = $pdo->prepare(
                'INSERT INTO variable '
                . '(variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind) '
                . 'VALUES (?, ?, ?, ?, ?, ?)',
            );
            $pdo->beginTransaction();
            for ($ordinal = 1; $ordinal <= 1599; ++$ordinal) {
                $insert->execute([NormativeCatalog::uuid(), $datasetId, $ordinal, 'V' . $ordinal, 'v' . $ordinal, 'numeric']);
            }
            $pdo->commit();

            $plan = new TransformationPlan(PlanContract::V02, 'parent', [
                new AssignOperation(
                    'Replacement',
                    TargetMode::Create,
                    new LiteralOperand(Binary64Value::fromBits('0000000000000000')),
                ),
            ]);
            $request = new InPlaceApplyRequest(
                $plan,
                'parent',
                $datasetId,
                hash('sha256', 'PostgreSQL physical-column slot fixture'),
                'integration-test',
            );

            try {
                (new InPlaceTransformationExecutor(new Connection($pdo)))->execute($request);
                self::fail('PostgreSQL accepted a replacement after the physical column limit was reached.');
            } catch (UnsupportedOperation $exception) {
                self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
                self::assertStringContainsString('cannot add another source variable to this wide table', $exception->getMessage());
            }

            self::assertSame(1599, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM variable WHERE dataset_id = ?', [$datasetId]));
            self::assertSame(
                1600,
                (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM pg_attribute WHERE attrelid = to_regclass(?) AND attnum > 0', ['"' . $tableName . '"']),
            );
        } finally {
            $pdo->prepare('DELETE FROM variable WHERE dataset_id = ?')->execute([$datasetId]);
            $pdo->prepare('DELETE FROM dataset WHERE dataset_id = ?')->execute([$datasetId]);
            $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($tableName));
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function finalizationFailures(): iterable
    {
        yield 'hook throws' => [false];
        yield 'normative success update fails' => [true];
    }

    #[DataProvider('finalizationFailures')]
    public function testImportFinalizationFailureRollsBackOnlyThisAttempt(bool $journalFailure): void
    {
        $pdo = $this->postgres();
        $schema = 'import_atomicity_' . bin2hex(random_bytes(6));
        $pdo->exec('CREATE SCHEMA ' . $this->quote($schema));
        try {
            $pdo->exec('SET search_path TO ' . $this->quote($schema));
            $fixture = $this->fixture('sav');
            $engine = new FakeSpssEngine(new Dataset(
                new VariableDictionary($fixture->variables()),
                array_merge(...array_fill(0, 257, $fixture->rows())),
                $fixture->metadata,
                $fixture->technicalMetadata,
            ));
            $prior = (new SpssAdapter($pdo, $engine))->import('prior.sav', 'prior');
            $before = [];
            foreach ($this->rows($pdo, "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' AND table_name NOT IN ('operation_catalog', 'operation', 'fidelity_event_catalog', 'fidelity_event')", []) as $table) {
                $before[$table['table_name']] = $this->rows($pdo, 'SELECT * FROM ' . $this->quote($table['table_name']), []);
            }
            $inTransaction = false;
            $injected = new RuntimeException('Injected finalization failure.');
            $adapter = new SpssAdapter($pdo, $engine, beforeImportFinalization: static function () use ($pdo, $prior, $journalFailure, $injected, &$inTransaction): void {
                $inTransaction = $pdo->inTransaction();
                if (!$journalFailure) {
                    throw $injected;
                }
                $pdo->exec("ALTER TABLE operation ADD CONSTRAINT reject_success CHECK (status <> 'succeeded' OR operation_id = '{$prior->operationId}')");
            });
            try {
                $adapter->import('attempt.sav', 'attempt');
                self::fail('Finalization failure was swallowed.');
            } catch (RuntimeException $exception) {
                if ($journalFailure) {
                    self::assertInstanceOf(PDOException::class, $exception);
                    self::assertStringContainsString('reject_success', $exception->getMessage());
                } else {
                    self::assertSame($injected, $exception);
                }
            }
            self::assertFalse($pdo->inTransaction());
            self::assertNull($this->scalar($pdo, "SELECT to_regclass('dataset_attempt')", []));
            foreach ($before as $table => $rows) {
                self::assertEqualsCanonicalizing($rows, $this->rows($pdo, 'SELECT * FROM ' . $this->quote($table), []), $table);
            }
            self::assertTrue($inTransaction, 'Finalization must share the dataset transaction.');
            self::assertSame([
                ['target_path' => 'attempt.sav', 'status' => 'failed', 'dataset_name' => null, 'normative_status' => 'failed'],
                ['target_path' => 'prior.sav', 'status' => 'succeeded', 'dataset_name' => 'prior', 'normative_status' => 'succeeded'],
            ], $this->rows($pdo, 'SELECT target_path, legacy.status, dataset_name, normative.status AS normative_status FROM operation_catalog legacy JOIN operation normative USING (operation_id) ORDER BY target_path', []));
            self::assertSame([['dataset_name' => null, 'code' => 'operation_failed']], $this->rows($pdo, 'SELECT dataset_name, code FROM fidelity_event_catalog', []));
            self::assertSame([['dataset_id' => null, 'event_code' => 'operation_failed']], $this->rows($pdo, 'SELECT dataset_id, event_code FROM fidelity_event', []));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec('SET search_path TO public');
            $pdo->exec('DROP SCHEMA ' . $this->quote($schema) . ' CASCADE');
        }
    }

    /** @return iterable<string, array{bool, string}> */
    public static function batchImports(): iterable
    {
        foreach (['native' => false, 'emulated' => true] as $mode => $emulated) {
            foreach (['mixed', 'wide NULL', 'late failure'] as $shape) {
                yield "$mode $shape" => [$emulated, $shape];
            }
        }
    }

    #[DataProvider('batchImports')]
    public function testBoundedCaseImportOnServer(bool $emulated, string $shape): void
    {
        $pdo = $this->postgres();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);
        $schema = 'case_batches_' . bin2hex(random_bytes(6));
        $searchPath = (string) $this->scalar($pdo, 'SHOW search_path', []);
        $pdo->exec('CREATE SCHEMA ' . $this->quote($schema));
        try {
            $pdo->exec('SET search_path TO ' . $this->quote($schema));
            $variables = $shape === 'wide NULL'
                ? array_map(static fn(int $i): array => ['name' => 'v' . $i, 'type' => 'numeric'], range(1, 1599))
                : [['name' => 'Score', 'type' => 'numeric'], ['name' => 'Comment', 'type' => 'string']];
            $rows = [];
            for ($i = 0; $i < ($shape === 'wide NULL' ? 81 : 513); ++$i) {
                $rows[] = $shape === 'wide NULL' ? array_fill(0, 1599, null)
                    : [[0.1, null, 42, 1.0000000000000002, PHP_FLOAT_MAX, 5.0e-324][$i % 6], $i % 2 === 0 ? "õ'\\\\ $i" : ''];
            }
            $importer = new PostgreSqlWideTableImporter($pdo);
            $importer->import(['variables' => $variables, 'data' => [$rows[0]]], 'prior');
            $prior = $this->rows($pdo, 'SELECT * FROM dataset_prior', []);
            if ($shape === 'late failure') {
                // Delegate to the live connection; only the owned attempt DDL gets a fault constraint.
                $faultPdo = $this->createMock(PDO::class);
                foreach (['getAttribute', 'setAttribute', 'inTransaction', 'beginTransaction', 'commit', 'rollBack', 'query', 'prepare'] as $method) {
                    $faultPdo->method($method)->willReturnCallback($pdo->$method(...));
                }
                $faultPdo->method('exec')->willReturnCallback(static function (string $sql) use ($pdo): int|false {
                    if (str_starts_with($sql, 'CREATE TABLE "dataset_attempt" ')) {
                        $sql = substr($sql, 0, -1) . ', CONSTRAINT reject_batch_tail CHECK (__case_ordinal < 257))';
                    }
                    return $pdo->exec($sql);
                });
                $finalized = false;
                try {
                    (new PostgreSqlWideTableImporter($faultPdo))->import(['variables' => $variables, 'data' => $rows], 'attempt', beforeCommit: static function () use (&$finalized): void {
                        $finalized = true;
                    });
                    self::fail('The server accepted the forbidden tail row.');
                } catch (PDOException $exception) {
                    self::assertStringContainsString('reject_batch_tail', $exception->getMessage());
                }
                self::assertFalse($finalized);
                self::assertNull($this->scalar($pdo, "SELECT to_regclass('dataset_attempt')", []));
                self::assertSame(0, (int) $this->scalar($pdo, "SELECT COUNT(*) FROM datasets WHERE dataset_name = 'attempt'", []));
                self::assertSame(0, (int) $this->scalar($pdo, "SELECT COUNT(*) FROM variables WHERE dataset_name = 'attempt'", []));
            } else {
                $definition = $importer->import(['variables' => $variables, 'data' => $rows], 'attempt');
                $actual = $this->rows($pdo, 'SELECT * FROM ' . $this->quote($definition->tableName) . ' ORDER BY __case_ordinal', []);
                self::assertCount(count($rows), $actual);
                foreach ($actual as $i => $row) {
                    self::assertSame($i + 1, (int) array_shift($row));
                    foreach (array_values($row) as $j => $value) {
                        $expected = $rows[$i][$j];
                        if (is_int($expected) || is_float($expected)) {
                            self::assertSame(pack('E', (float) $expected), pack('E', (float) $value));
                        } else {
                            self::assertSame($expected, $value);
                        }
                    }
                }
            }
            self::assertSame($prior, $this->rows($pdo, 'SELECT * FROM dataset_prior', []));
            self::assertFalse($pdo->inTransaction());
            self::assertSame($emulated, $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->prepare("SELECT set_config('search_path', ?, false)")->execute([$searchPath]);
            $pdo->exec('DROP SCHEMA ' . $this->quote($schema) . ' CASCADE');
        }
    }

    private function postgres(): PDO
    {
        $dsn = getenv('OPENSTATSPEC_PG_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set OPENSTATSPEC_PG_DSN to run PostgreSQL integration tests.');
        }
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO pgsql is not available in this PHP environment.');
        }

        $user = getenv('OPENSTATSPEC_PG_USER');
        $password = getenv('OPENSTATSPEC_PG_PASSWORD');

        return new ExportCountingPdo(
            $dsn,
            is_string($user) ? $user : null,
            is_string($password) ? $password : null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function fixture(string $format): Dataset
    {
        $longText = str_repeat("\xC3\xB5", 170);

        return new Dataset(
            new VariableDictionary([
                new VariableMetadata(
                    name: 'Score',
                    type: VariableType::NUMERIC,
                    width: 0,
                    printFormat: new VariableFormat(5, 8, 2),
                    writeFormat: new VariableFormat(5, 8, 2),
                    label: 'Result score',
                    valueLabels: new ValueLabelSet([new ValueLabel(7.5, 'Seven and a half')], ['Score']),
                    missingValues: MissingValues::rangeAndValue(-99.0, -1.0, 999.0),
                    measure: Measure::SCALE,
                    alignment: Alignment::RIGHT,
                    columns: 12,
                    role: VariableRole::TARGET,
                    attributes: [new VariableAttribute('Score', 'Origin', ['integration'])],
                    dictionaryIndex: 1,
                ),
                new VariableMetadata(
                    name: 'Reason',
                    type: VariableType::STRING,
                    width: 20,
                    printFormat: new VariableFormat(1, 20),
                    writeFormat: new VariableFormat(1, 20),
                    missingValues: MissingValues::discrete('MISSING'),
                    measure: Measure::NOMINAL,
                    alignment: Alignment::LEFT,
                    columns: 20,
                    role: VariableRole::INPUT,
                    dictionaryIndex: 2,
                ),
                new VariableMetadata(
                    name: 'LongText',
                    type: VariableType::STRING,
                    width: 400,
                    printFormat: new VariableFormat(1, 255),
                    writeFormat: new VariableFormat(1, 255),
                    dictionaryIndex: 3,
                ),
            ]),
            [[7.5, 'present', $longText], [null, 'MISSING', '']],
            new FileMetadata(
                'PostgreSQL integration fixture',
                weightVariableName: 'Score',
                documents: ['First document line', 'Second document line'],
                attributes: [new FileAttribute('Source', ['PostgreSQL integration'])],
                variableSets: [new VariableSet('Core', ['Score', 'Reason'])],
                multipleResponseSets: [
                    new MultipleResponseSet(
                        '$Reason',
                        MultipleResponseSetType::DICHOTOMY,
                        ['Reason'],
                        'Reason selected',
                        'present',
                        MultipleResponseCategoryLabels::COUNTED_VALUES,
                        MultipleResponseLabelSource::VARIABLE_LABEL,
                    ),
                ],
            ),
            new FileTechnicalMetadata(sourceFormat: $format, compression: $format === 'zsav' ? 2 : 1),
        );
    }

    private function tableName(PDO $pdo, string $datasetName): string
    {
        $statement = $pdo->prepare('SELECT table_name FROM datasets WHERE dataset_name = ?');
        $statement->execute([$datasetName]);
        $tableName = $statement->fetchColumn();
        if (!is_string($tableName) || $tableName === '') {
            throw new RuntimeException('The PostgreSQL dataset catalogue entry was not created.');
        }

        return $tableName;
    }

    /** @param list<mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(PDO $pdo, string $sql, array $parameters): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<mixed> $parameters */
    private function scalar(PDO $pdo, string $sql, array $parameters): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    private function cleanup(PDO $pdo, string $datasetName, ?string $tableName): void
    {
        if ($tableName !== null) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($tableName));
        }
        foreach ([
            'multiple_response_set_members',
            'multiple_response_sets',
            'variable_set_members',
            'variable_sets',
            'variable_attributes',
            'file_attributes',
            'variable_roles',
            'variable_display_metadata',
            'missing_rule_values',
            'missing_rules',
            'value_labels',
            'documents',
            'file_technical_metadata',
            'dataset_metadata',
            'variables',
            'datasets',
        ] as $catalogue) {
            if (!$this->catalogueTableExists($pdo, $catalogue)) {
                continue;
            }

            $statement = $pdo->prepare('DELETE FROM ' . $catalogue . ' WHERE dataset_name = ?');
            $statement->execute([$datasetName]);
        }
    }

    private function catalogueTableExists(PDO $pdo, string $catalogue): bool
    {
        try {
            $pdo->query('SELECT 1 FROM ' . $catalogue . ' WHERE 1 = 0');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function fileHeader(string $path): string
    {
        $header = file_get_contents($path, false, null, 0, 4);
        if (!is_string($header)) {
            throw new RuntimeException('Could not read the SPSS file header.');
        }

        return $header;
    }
}
