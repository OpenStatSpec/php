<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\PhpSpssEngine;
use OpenStatSpec\Spss\SpssAdapter;
use OpenStatSpec\Spss\SpssMissingValueSentinel;
use OpenStatSpec\Sql\OperationJournal;
use OpenStatSpec\Tests\Support\FakeSpssEngine;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SPSS\Sav\Dataset;
use SPSS\Sav\FileMetadata;
use SPSS\Sav\FileTechnicalMetadata;
use SPSS\Sav\MissingValues;
use SPSS\Sav\ValueLabelSet;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableMetadata;

final class OfficialSpssConformanceManifestTest extends TestCase
{
    public function testEveryManifestRequirementRunsThroughEveryAvailableProfile(): void
    {
        $specification = $this->specificationRoot();
        $manifest = $this->manifest($specification);
        $engine = new PhpSpssEngine();

        foreach ($this->profiles() as $profile => $pdo) {
            $adapter = new SpssAdapter($pdo, $engine);
            $this->assertRequiredCapabilities($manifest, $adapter, $profile);
            $executed = [];
            foreach ($manifest['fixtures'] as $fixture) {
                self::assertIsArray($fixture);
                $id = $fixture['id'] ?? null;
                $source = $fixture['source'] ?? null;
                $directions = $fixture['directions'] ?? null;
                $rawExpectations = $fixture['expects'] ?? null;
                $expectedCatalog = $fixture['expected_catalog'] ?? null;
                self::assertIsString($id);
                self::assertIsString($source);
                self::assertIsArray($directions);
                self::assertIsArray($rawExpectations);
                self::assertTrue(array_is_list($rawExpectations));
                self::assertNotSame([], $directions);
                self::assertNotSame([], $rawExpectations);
                if ($expectedCatalog !== null) {
                    self::assertIsArray($expectedCatalog);
                    self::assertNotSame([], $expectedCatalog);
                }
                $expectations = [];
                foreach ($rawExpectations as $expectation) {
                    self::assertIsString($expectation);
                    $expectations[] = $expectation;
                }

                if ($id === 'preflight-failure') {
                    self::assertSame(['import'], $directions);
                    $this->assertPreflightFailure($pdo, $profile, $adapter->capabilities(), $expectations);
                    $executed[] = $id;
                    continue;
                }

                $sourcePath = $specification . '/conformance/' . $source;
                self::assertFileExists($sourcePath, 'Missing official fixture: ' . $id);
                $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
                self::assertContains($extension, ['sav', 'zsav']);
                self::assertContains('import', $directions, $id . ': fixture must exercise import');
                $token = bin2hex(random_bytes(8));
                $datasetName = 'official_' . $profile . '_' . str_replace('-', '_', $id) . '_' . $token;
                $targetPath = sys_get_temp_dir() . '/openstatspec-official-' . $token . '.' . $extension;
                $sourceDataset = $engine->read($sourcePath);
                $roundTripCompared = false;

                try {
                    $import = $adapter->import($sourcePath, $datasetName);
                    self::assertSame([], $import->diagnostics);
                    $this->assertCanonicalShape($pdo, $datasetName, $sourceDataset);
                    if (is_array($expectedCatalog)) {
                        $this->assertExpectedCatalog($pdo, $datasetName, $expectedCatalog, $profile . '/' . $id);
                    }

                    if (in_array('export', $directions, true)) {
                        $export = $adapter->export($datasetName, $targetPath);
                        self::assertSame([], $export->diagnostics);
                    }
                    if (in_array('semantic_round_trip', $directions, true)) {
                        self::assertFileExists($targetPath);
                        $roundTrip = $engine->read($targetPath);
                        $context = $profile . '/' . $id;
                        self::assertEquals($sourceDataset->rows(), $roundTrip->rows(), $context . ': cases or case order');
                        self::assertEquals($this->normativeVariables($sourceDataset->variables()), $this->normativeVariables($roundTrip->variables()), $context . ': variable dictionary');
                        self::assertEquals($this->normativeMetadata($sourceDataset->metadata), $this->normativeMetadata($roundTrip->metadata), $context . ': file metadata');
                        self::assertSame($sourceDataset->technicalMetadata->sourceFormat, $roundTrip->technicalMetadata->sourceFormat, $context . ': source format');
                        self::assertSame($sourceDataset->technicalMetadata->encoding, $roundTrip->technicalMetadata->encoding, $context . ': source encoding');
                        $roundTripCompared = true;
                    }
                    foreach ($expectations as $expectation) {
                        $this->assertCanonicalExpectation($pdo, $datasetName, $expectation, $roundTripCompared);
                    }
                    $executed[] = $id;
                } finally {
                    @unlink($targetPath);
                }
            }
            self::assertSame(array_column($manifest['fixtures'], 'id'), $executed, $profile . ': every manifest fixture must execute');
        }
    }

    /** @return array<string, mixed> */
    private function manifest(string $specification): array
    {
        $contents = file_get_contents($specification . '/conformance/spss-sav-zsav-1.0.json');
        if (!is_string($contents)) {
            throw new RuntimeException('Could not read the official conformance manifest.');
        }
        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame('1.0', $manifest['manifest_version'] ?? null);
        self::assertIsArray($manifest['required_capabilities'] ?? null);
        self::assertIsArray($manifest['fixtures'] ?? null);
        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    private function assertRequiredCapabilities(array $manifest, SpssAdapter $adapter, string $profile): void
    {
        $declaration = $adapter->capabilities();
        self::assertSame($profile, $declaration['active_connection']['profile']);
        self::assertTrue($declaration['active_connection']['claimed_supported'], $profile . ': active server version is outside the claimed profile');
        self::assertSame($declaration['sql_profiles'][$profile]['claimed_server_versions'], $declaration['active_connection']['matched_claim']);
        self::assertTrue($declaration['engine']['claimed_supported']);
        self::assertSame(['3.0.0'], $declaration['engine']['ci_tested_versions']);
        self::assertContains($profile, array_keys($declaration['sql_profiles'] ?? []));
        self::assertContains('import', $declaration['directions'] ?? []);
        self::assertContains('export', $declaration['directions'] ?? []);
        foreach ($manifest['required_capabilities'] as $capability) {
            self::assertIsString($capability);
            self::assertTrue($declaration['required_capabilities'][$capability] ?? false, $profile . ': missing declared capability ' . $capability);
        }
    }

    /** @param array<string, mixed> $expected */
    private function assertExpectedCatalog(PDO $pdo, string $datasetName, array $expected, string $context): void
    {
        $datasetId = (string) $this->scalar($pdo, 'SELECT dataset_id FROM dataset WHERE dataset_name = ?', [$datasetName]);

        if (array_key_exists('weight_variable', $expected)) {
            $actual = $this->scalar($pdo, 'SELECT variable.source_name FROM dataset_weight_variable weight JOIN variable ON variable.variable_id = weight.variable_id WHERE weight.dataset_id = ?', [$datasetId]);
            self::assertSame($expected['weight_variable'], $actual, $context . ': weight variable');
        }

        if (array_key_exists('value_labels', $expected)) {
            $actual = array_map(static function (array $row): array {
                $string = $row['code_kind'] === 'string';
                return [
                    'variable' => (string) $row['source_name'],
                    'ordinal' => (int) $row['ordinal'],
                    'kind' => (string) $row['code_kind'],
                    'value' => $string ? (string) $row['string_code'] : (float) $row['numeric_code'],
                    'label' => (string) $row['label'],
                ];
            }, $this->rows($pdo, 'SELECT variable.source_name, label.ordinal, label.code_kind, label.numeric_code, label.string_code, label.label FROM variable JOIN variable_value_label_set link ON link.variable_id = variable.variable_id JOIN value_label label ON label.value_label_set_id = link.value_label_set_id WHERE variable.dataset_id = ? ORDER BY variable.source_ordinal, label.ordinal', [$datasetId]));
            self::assertSame($expected['value_labels'], $actual, $context . ': typed ordered value labels');
        }

        if (array_key_exists('dataset_attributes', $expected)) {
            $actual = [];
            foreach ($this->rows($pdo, 'SELECT attribute_name, array_ordinal, attribute_value FROM dataset_attribute WHERE dataset_id = ? ORDER BY attribute_name, array_ordinal', [$datasetId]) as $row) {
                $key = (string) $row['attribute_name'];
                if (!isset($actual[$key])) {
                    $actual[$key] = ['name' => $key, 'values' => []];
                }
                $actual[$key]['values'][] = (string) $row['attribute_value'];
            }
            self::assertSame($expected['dataset_attributes'], array_values($actual), $context . ': dataset attribute arrays');
        }

        if (array_key_exists('variable_attributes', $expected)) {
            $actual = [];
            foreach ($this->rows($pdo, 'SELECT variable.source_name, attribute.attribute_name, attribute.array_ordinal, attribute.attribute_value FROM variable_attribute attribute JOIN variable ON variable.variable_id = attribute.variable_id WHERE variable.dataset_id = ? ORDER BY variable.source_ordinal, attribute.attribute_name, attribute.array_ordinal', [$datasetId]) as $row) {
                $key = (string) $row['source_name'] . "\0" . (string) $row['attribute_name'];
                if (!isset($actual[$key])) {
                    $actual[$key] = ['variable' => (string) $row['source_name'], 'name' => (string) $row['attribute_name'], 'values' => []];
                }
                $actual[$key]['values'][] = (string) $row['attribute_value'];
            }
            self::assertSame($expected['variable_attributes'], array_values($actual), $context . ': variable attribute arrays');
        }

        if (array_key_exists('variable_sets', $expected)) {
            $actual = [];
            foreach ($this->rows($pdo, 'SELECT variable_set_id, source_ordinal, set_name FROM variable_set WHERE dataset_id = ? ORDER BY source_ordinal', [$datasetId]) as $set) {
                $members = array_map(
                    static fn(array $row): string => (string) $row['source_name'],
                    $this->rows($pdo, 'SELECT variable.source_name FROM variable_set_member member JOIN variable ON variable.variable_id = member.variable_id WHERE member.variable_set_id = ? ORDER BY member.source_ordinal', [(string) $set['variable_set_id']]),
                );
                $actual[] = ['ordinal' => (int) $set['source_ordinal'], 'name' => (string) $set['set_name'], 'members' => $members];
            }
            self::assertSame($expected['variable_sets'], $actual, $context . ': ordered variable sets');
        }

        if (array_key_exists('multiple_response_sets', $expected)) {
            $actual = [];
            foreach ($this->rows($pdo, 'SELECT multiple_response_set_id, source_ordinal, set_name, set_label, set_kind, counted_value_kind, counted_numeric_value, counted_string_value, category_label_behavior, label_source FROM multiple_response_set WHERE dataset_id = ? ORDER BY source_ordinal', [$datasetId]) as $set) {
                $members = array_map(
                    static fn(array $row): string => (string) $row['source_name'],
                    $this->rows($pdo, 'SELECT variable.source_name FROM multiple_response_member member JOIN variable ON variable.variable_id = member.variable_id WHERE member.multiple_response_set_id = ? ORDER BY member.source_ordinal', [(string) $set['multiple_response_set_id']]),
                );
                $kind = $set['counted_value_kind'];
                $counted = $kind === 'numeric' ? (float) $set['counted_numeric_value'] : ($kind === 'string' ? (string) $set['counted_string_value'] : null);
                $actual[] = [
                    'ordinal' => (int) $set['source_ordinal'],
                    'name' => (string) $set['set_name'],
                    'kind' => (string) $set['set_kind'],
                    'label' => $set['set_label'],
                    'counted_kind' => $kind,
                    'counted_value' => $counted,
                    'category_labels' => $set['category_label_behavior'],
                    'label_source' => $set['label_source'],
                    'members' => $members,
                ];
            }
            self::assertSame($expected['multiple_response_sets'], $actual, $context . ': ordered multiple-response sets');
        }
    }

    /**
     * @param list<mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(PDO $pdo, string $sql, array $parameters): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return array_values($rows);
    }

    private function assertCanonicalShape(PDO $pdo, string $datasetName, Dataset $source): void
    {
        self::assertSame(1, $this->scalarCount($pdo, 'SELECT COUNT(*) FROM dataset WHERE dataset_name = ?', [$datasetName]));
        $datasetId = $this->scalar($pdo, 'SELECT dataset_id FROM dataset WHERE dataset_name = ?', [$datasetName]);
        self::assertIsString($datasetId);
        self::assertSame(count($source->variables()), $this->scalarCount($pdo, 'SELECT COUNT(*) FROM variable WHERE dataset_id = ?', [$datasetId]));
        self::assertSame($source->rowCount(), (int) $this->scalar($pdo, 'SELECT source_case_count FROM dataset WHERE dataset_id = ?', [$datasetId]));
        $table = $this->scalar($pdo, 'SELECT physical_table_name FROM dataset WHERE dataset_id = ?', [$datasetId]);
        self::assertIsString($table);
        $profile = (new \OpenStatSpec\Sql\Connection($pdo))->profile;
        $countStatement = $pdo->query('SELECT COUNT(*) FROM ' . $profile->quoteIdentifier($table));
        self::assertInstanceOf(\PDOStatement::class, $countStatement);
        self::assertSame($source->rowCount(), (int) $countStatement->fetchColumn());
    }

    private function assertCanonicalExpectation(PDO $pdo, string $datasetName, string $expectation, bool $roundTripCompared): void
    {
        $datasetId = (string) $this->scalar($pdo, 'SELECT dataset_id FROM dataset WHERE dataset_name = ?', [$datasetName]);
        $count = fn(string $sql): int => $this->scalarCount($pdo, $sql, [$datasetId]);
        $semantic = [
            'binary64_values', 'string_values', 'numeric_system_missing', 'blank_string_not_missing',
            'raw_user_missing_values', 'no_string_truncation', 'dictionary_preserved', 'values_preserved',
            'zsav_zlib_decode', 'zsav_zlib_encode',
        ];
        if (in_array($expectation, $semantic, true)) {
            self::assertTrue($roundTripCompared, $expectation . ' requires a semantic round trip');
            return;
        }
        match ($expectation) {
            'case_order' => $this->assertCaseOrdinals($pdo, $datasetId),
            'variable_order' => $this->assertVariableOrdinals($pdo, $datasetId),
            'file_label' => self::assertNotNull($this->scalar($pdo, 'SELECT dataset_label FROM dataset WHERE dataset_id = ?', [$datasetId])),
            'documents_order' => self::assertGreaterThan(0, $count('SELECT COUNT(*) FROM document WHERE dataset_id = ?')),
            'weight_variable' => self::assertSame(1, $count('SELECT COUNT(*) FROM dataset_weight_variable WHERE dataset_id = ?')),
            'variable_labels' => self::assertGreaterThan(0, $count('SELECT COUNT(*) FROM variable WHERE dataset_id = ? AND variable_label IS NOT NULL')),
            'print_write_formats' => self::assertSame($count('SELECT COUNT(*) FROM variable WHERE dataset_id = ?'), $count('SELECT COUNT(*) FROM variable WHERE dataset_id = ? AND print_format_family IS NOT NULL AND write_format_family IS NOT NULL')),
            'measurement_level' => self::assertSame($count('SELECT COUNT(*) FROM variable WHERE dataset_id = ?'), $count('SELECT COUNT(*) FROM variable WHERE dataset_id = ? AND measurement_level IS NOT NULL')),
            'variable_role' => self::assertSame($count('SELECT COUNT(*) FROM variable WHERE dataset_id = ?'), $count('SELECT COUNT(*) FROM variable WHERE dataset_id = ? AND variable_role IS NOT NULL')),
            'display_width' => self::assertSame($count('SELECT COUNT(*) FROM variable WHERE dataset_id = ?'), $count('SELECT COUNT(*) FROM variable WHERE dataset_id = ? AND display_width IS NOT NULL')),
            'display_alignment' => self::assertSame($count('SELECT COUNT(*) FROM variable WHERE dataset_id = ?'), $count('SELECT COUNT(*) FROM variable WHERE dataset_id = ? AND display_alignment IS NOT NULL')),
            'value_labels_typed_ordered', 'long_string_value_labels' => self::assertGreaterThan(0, $count('SELECT COUNT(*) FROM value_label label JOIN value_label_set set_table ON set_table.value_label_set_id = label.value_label_set_id WHERE set_table.dataset_id = ?')),
            'discrete_numeric_missing' => self::assertGreaterThan(0, $count("SELECT COUNT(*) FROM missing_rule rule JOIN variable ON variable.variable_id = rule.variable_id WHERE variable.dataset_id = ? AND rule.rule_kind = 'discrete' AND rule.code_kind = 'numeric'")),
            'discrete_string_missing' => self::assertGreaterThan(0, $count("SELECT COUNT(*) FROM missing_rule rule JOIN variable ON variable.variable_id = rule.variable_id WHERE variable.dataset_id = ? AND rule.rule_kind = 'discrete' AND rule.code_kind = 'string'")),
            'numeric_range_missing' => self::assertGreaterThan(0, $count("SELECT COUNT(*) FROM missing_rule rule JOIN variable ON variable.variable_id = rule.variable_id WHERE variable.dataset_id = ? AND rule.rule_kind = 'numeric_range'")),
            'lowest_highest_missing' => self::assertGreaterThanOrEqual(2, $count("SELECT COUNT(*) FROM missing_rule rule JOIN variable ON variable.variable_id = rule.variable_id WHERE variable.dataset_id = ? AND (rule.lower_special = 'LOWEST' OR rule.upper_special = 'HIGHEST')")),
            'range_plus_discrete_missing' => self::assertGreaterThan(0, $count("SELECT COUNT(*) FROM variable WHERE dataset_id = ? AND variable_id IN (SELECT variable_id FROM missing_rule GROUP BY variable_id HAVING COUNT(*) > 1)")),
            'utf8_source_encoding' => self::assertSame('UTF-8', strtoupper((string) $this->scalar($pdo, 'SELECT source_encoding FROM dataset WHERE dataset_id = ?', [$datasetId]))),
            'string_over_255_bytes' => self::assertGreaterThan(0, $count("SELECT COUNT(*) FROM variable WHERE dataset_id = ? AND storage_kind = 'string' AND declared_string_width > 255")),
            'dataset_attribute_arrays' => self::assertGreaterThan(1, $count('SELECT COUNT(*) FROM dataset_attribute WHERE dataset_id = ?')),
            'variable_attribute_arrays' => self::assertGreaterThan(1, $count('SELECT COUNT(*) FROM variable_attribute attribute JOIN variable ON variable.variable_id = attribute.variable_id WHERE variable.dataset_id = ?')),
            'variable_sets_ordered' => self::assertGreaterThan(0, $count('SELECT COUNT(*) FROM variable_set WHERE dataset_id = ? AND source_ordinal IS NOT NULL')),
            'multiple_response_md' => self::assertGreaterThan(0, $count("SELECT COUNT(*) FROM multiple_response_set WHERE dataset_id = ? AND set_kind = 'MD'")),
            'multiple_response_mc' => self::assertGreaterThan(0, $count("SELECT COUNT(*) FROM multiple_response_set WHERE dataset_id = ? AND set_kind = 'MC'")),
            'multiple_response_members_ordered' => self::assertGreaterThan(0, $count('SELECT COUNT(*) FROM multiple_response_member member JOIN multiple_response_set set_table ON set_table.multiple_response_set_id = member.multiple_response_set_id WHERE set_table.dataset_id = ?')),
            'multiple_response_counted_value' => self::assertGreaterThan(0, $count('SELECT COUNT(*) FROM multiple_response_set WHERE dataset_id = ? AND counted_value_kind IS NOT NULL')),
            'multiple_response_string_counted_value' => self::assertGreaterThan(0, $count("SELECT COUNT(*) FROM multiple_response_set WHERE dataset_id = ? AND counted_value_kind = 'string' AND counted_string_value IS NOT NULL")),
            'multiple_response_category_label_behavior' => self::assertGreaterThan(0, $count('SELECT COUNT(*) FROM multiple_response_set WHERE dataset_id = ? AND category_label_behavior IS NOT NULL')),
            'multiple_response_label_source' => self::assertGreaterThan(0, $count('SELECT COUNT(*) FROM multiple_response_set WHERE dataset_id = ? AND label_source IS NOT NULL')),
            default => throw new RuntimeException('Unimplemented conformance expectation: ' . $expectation),
        };
    }

    /**
     * @param array<string, mixed> $capabilities
     * @param list<string>         $expectations
     */
    private function assertPreflightFailure(PDO $pdo, string $profile, array $capabilities, array $expectations): void
    {
        $maximum = (int) $capabilities['sql_profiles'][$profile]['effective_limits']['maximum_source_variables'];
        $variables = [];
        for ($index = 1; $index <= $maximum + 1; ++$index) {
            $name = 'v' . $index;
            $variables[] = new VariableMetadata(
                $name,
                \SPSS\Sav\VariableType::NUMERIC,
                0,
                new \SPSS\Sav\VariableFormat(5, 8, 0),
                new \SPSS\Sav\VariableFormat(5, 8, 0),
                valueLabels: new ValueLabelSet([], [$name]),
                dictionaryIndex: $index,
            );
        }
        $source = new Dataset(new VariableDictionary($variables), [array_fill(0, $maximum + 1, 1.0)], new FileMetadata(), new FileTechnicalMetadata(sourceFormat: 'sav'));
        $adapter = new SpssAdapter($pdo, new FakeSpssEngine($source));
        $adapter->migrateCatalog();
        $bootstrap = new OperationJournal($pdo);
        $bootstrapOperation = $bootstrap->start('import', null, 'conformance-bootstrap.sav', sourceFormat: 'sav');
        $bootstrap->succeed($bootstrapOperation, null, []);
        $tablesBefore = $this->tableNames($pdo);
        $datasetName = 'preflight_' . $profile . '_' . bin2hex(random_bytes(5));
        try {
            $adapter->import('preflight-too-wide.sav', $datasetName);
            self::fail($profile . ': preflight fixture unexpectedly imported');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
        }
        $operationId = $this->scalar($pdo, 'SELECT operation_id FROM operation_catalog WHERE target_path = ? ORDER BY started_at DESC LIMIT 1', ['preflight-too-wide.sav']);
        self::assertIsString($operationId);
        $operation = $this->rows($pdo, 'SELECT operation_kind, status, source_format, started_at, completed_at FROM operation WHERE operation_id = ?', [$operationId]);
        self::assertCount(1, $operation);
        self::assertSame('import', $operation[0]['operation_kind']);
        self::assertSame('failed', $operation[0]['status']);
        self::assertSame('sav', $operation[0]['source_format']);
        self::assertNotSame('', (string) $operation[0]['started_at']);
        self::assertNotSame('', (string) $operation[0]['completed_at']);

        $events = $this->rows($pdo, 'SELECT dataset_id, direction, severity, event_code, source_item, detail_json, created_at FROM fidelity_event WHERE operation_id = ? ORDER BY created_at, fidelity_event_id', [$operationId]);
        self::assertCount(1, $events);
        self::assertNull($events[0]['dataset_id']);
        self::assertSame('import', $events[0]['direction']);
        self::assertSame('error', $events[0]['severity']);
        self::assertSame(DiagnosticCode::TargetCapabilityExceeded->value, $events[0]['event_code']);
        self::assertSame('preflight-too-wide.sav', $events[0]['source_item']);
        self::assertIsArray(json_decode((string) $events[0]['detail_json'], true, flags: JSON_THROW_ON_ERROR));
        self::assertNotSame('', (string) $events[0]['created_at']);

        foreach ($expectations as $expectation) {
            match ($expectation) {
                'atomic_failure' => self::assertSame($tablesBefore, $this->tableNames($pdo), $profile . ': preflight changed the database schema'),
                'no_dataset_row' => self::assertSame(0, $this->scalarCount($pdo, 'SELECT COUNT(*) FROM dataset WHERE dataset_name = ?', [$datasetName])),
                'no_data_table' => self::assertSame($tablesBefore, $this->tableNames($pdo), $profile . ': preflight left a physical table behind'),
                'operation_record' => self::assertSame('failed', $operation[0]['status']),
                'fidelity_event_null_dataset_id' => self::assertNull($events[0]['dataset_id']),
                'target_capability_exceeded' => self::assertSame(DiagnosticCode::TargetCapabilityExceeded->value, $events[0]['event_code']),
                default => throw new RuntimeException('Unimplemented preflight expectation: ' . $expectation),
            };
        }
        self::assertSame(0, $this->scalarCount($pdo, 'SELECT COUNT(*) FROM datasets WHERE dataset_name = ?', [$datasetName]));
    }

    /** @return list<string> */
    private function tableNames(PDO $pdo): array
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = match ($driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            'pgsql' => "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = current_schema() ORDER BY tablename",
            'mysql' => 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name',
            default => throw new RuntimeException('Unsupported conformance database driver.'),
        };
        $statement = $pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $statement);
        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function assertCaseOrdinals(PDO $pdo, string $datasetId): void
    {
        $table = (string) $this->scalar($pdo, 'SELECT physical_table_name FROM dataset WHERE dataset_id = ?', [$datasetId]);
        $profile = (new \OpenStatSpec\Sql\Connection($pdo))->profile;
        $statement = $pdo->query('SELECT ' . $profile->quoteIdentifier('__case_ordinal') . ' FROM ' . $profile->quoteIdentifier($table) . ' ORDER BY ' . $profile->quoteIdentifier('__case_ordinal'));
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(range(1, count($rows)), array_map('intval', $rows));
    }

    private function assertVariableOrdinals(PDO $pdo, string $datasetId): void
    {
        $statement = $pdo->prepare('SELECT source_ordinal FROM variable WHERE dataset_id = ? ORDER BY source_ordinal');
        $statement->execute([$datasetId]);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(range(1, count($rows)), array_map('intval', $rows));
    }

    /** @param list<mixed> $parameters */
    private function scalar(PDO $pdo, string $sql, array $parameters): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }

    /** @param list<mixed> $parameters */
    private function scalarCount(PDO $pdo, string $sql, array $parameters): int
    {
        return (int) $this->scalar($pdo, $sql, $parameters);
    }

    /** @return array<string, PDO> */
    private function profiles(): array
    {
        $profiles = [];
        if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $profiles['sqlite'] = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        foreach (['mysql' => 'OPENSTATSPEC_MYSQL', 'mariadb' => 'OPENSTATSPEC_MARIADB', 'postgresql' => 'OPENSTATSPEC_PG'] as $name => $prefix) {
            $dsn = getenv($prefix . '_DSN');
            $driver = $name === 'postgresql' ? 'pgsql' : 'mysql';
            if (!is_string($dsn) || $dsn === '' || !in_array($driver, PDO::getAvailableDrivers(), true)) {
                continue;
            }
            $user = getenv($prefix . '_USER');
            $password = getenv($prefix . '_PASSWORD');
            $profiles[$name] = new PDO($dsn, is_string($user) ? $user : null, is_string($password) ? $password : null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        if ($profiles === []) {
            self::markTestSkipped('No supported PDO profile is available.');
        }
        return $profiles;
    }

    /**
     * @param list<VariableMetadata> $variables
     * @return list<array<string, mixed>>
     */
    private function normativeVariables(array $variables): array
    {
        return array_map(fn(VariableMetadata $variable): array => [
            'name' => $variable->name,
            'type' => $variable->type,
            'width' => $variable->width,
            'printFormat' => $variable->printFormat,
            'writeFormat' => $variable->writeFormat,
            'label' => $variable->label,
            'valueLabels' => $variable->valueLabels->labels(),
            'missingValues' => $this->normativeMissingValues($variable->missingValues),
            'measure' => $variable->measure,
            'alignment' => $variable->alignment,
            'columns' => $variable->columns,
            'role' => $variable->role,
            'attributes' => $variable->attributes(),
        ], $variables);
    }

    /** @return array<string, mixed> */
    private function normativeMetadata(FileMetadata $metadata): array
    {
        return ['label' => $metadata->label, 'weightVariableName' => $metadata->weightVariableName, 'documents' => $metadata->documents(), 'attributes' => $metadata->attributes(), 'variableSets' => $metadata->variableSets(), 'multipleResponseSets' => $metadata->multipleResponseSets()];
    }

    /** @return array<string, mixed> */
    private function normativeMissingValues(MissingValues $missing): array
    {
        $endpoint = static function (int|float|null $value): int|float|string|null {
            if (is_int($value) || is_float($value)) {
                if (SpssMissingValueSentinel::isLowest($value)) {
                    return 'LOWEST';
                }
                if (SpssMissingValueSentinel::isHighest($value)) {
                    return 'HIGHEST';
                }
            }
            return $value;
        };
        return ['kind' => $missing->kind->value, 'discrete' => $missing->discreteValues(), 'lower' => $endpoint($missing->lower), 'upper' => $endpoint($missing->upper), 'additional' => $missing->additionalValue];
    }

    private function specificationRoot(): string
    {
        $configured = getenv('OPENSTATSPEC_SPECIFICATION_DIR');
        $candidates = [];
        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured;
        }
        $candidates[] = dirname(__DIR__, 2) . '/openstatspec-specification';
        $candidates[] = dirname(__DIR__, 3) . '/specification';
        foreach ($candidates as $candidate) {
            $root = realpath($candidate);
            if (is_string($root) && is_file($root . '/conformance/spss-sav-zsav-1.0.json')) {
                return $root;
            }
        }
        throw new RuntimeException('The official OpenStatSpec specification checkout is required. Set OPENSTATSPEC_SPECIFICATION_DIR to its root.');
    }
}
