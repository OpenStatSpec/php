<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

use OpenStatSpec\Core\Binary64;
use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\PhpSpssEngine;
use OpenStatSpec\Spss\SpssAdapter;
use OpenStatSpec\Sql\DoltProfile;
use OpenStatSpec\Sql\MySqlWideTableImporter;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Tests\Support\FakeSpssEngine;
use PDO;
use PDOException;
use RuntimeException;
use SPSS\Sav\Dataset;
use SPSS\Sav\FileMetadata;
use SPSS\Sav\FileTechnicalMetadata;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableFormat;
use SPSS\Sav\VariableMetadata;
use SPSS\Sav\VariableType;

/**
 * Real Dolt 2.2.2/2.2.3 round-trip coverage. Requires OPENSTATSPEC_DOLT_* variables.
 */
final class DoltSpssRoundTripTest extends MySqlFamilySpssRoundTripTestCase
{
    public function testValidated305SourceVariableEnvelopeRoundTripsAnd306RejectsBeforeDdl(): void
    {
        $pdo = $this->mysql();
        $engine = new PhpSpssEngine();

        foreach (['sav' => '$FL2', 'zsav' => '$FL3'] as $format => $header) {
            $token = bin2hex(random_bytes(6));
            $datasetName = 'dolt envelope ' . $format . ' ' . $token;
            $tooWideName = 'dolt too wide ' . $format . ' ' . $token;
            $sourcePath = sys_get_temp_dir() . '/openstatspec-dolt-envelope-source-' . $token . '.' . $format;
            $targetPath = sys_get_temp_dir() . '/openstatspec-dolt-envelope-target-' . $token . '.' . $format;
            $tooWidePath = sys_get_temp_dir() . '/openstatspec-dolt-too-wide-' . $token . '.' . $format;
            $tableName = null;
            $tooWideOperationId = null;

            try {
                $fixture = $this->envelopeFixture($format, 305);
                $engine->write($sourcePath, $fixture);
                self::assertSame($header, $this->fileHeader($sourcePath));

                $adapter = new SpssAdapter($pdo, $engine);
                $adapter->import($sourcePath, $datasetName);
                $tableName = $this->tableName($pdo, $datasetName);

                self::assertSame(306, (int) $this->scalar(
                    $pdo,
                    'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    [$tableName],
                ));
                self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM ' . $this->quote($tableName), []));

                $result = $adapter->export($datasetName, $targetPath);
                self::assertSame([], $result->diagnostics);
                self::assertSame(1, $result->caseCount);
                self::assertSame($header, $this->fileHeader($targetPath));

                $roundTrip = $engine->read($targetPath);
                self::assertSame($fixture->rows(), $roundTrip->rows());
                self::assertCount(305, $roundTrip->variables());
                self::assertSame('Weight', $roundTrip->metadata->weightVariableName);
                self::assertSame('short value', $roundTrip->rows()[0][1]);
                self::assertSame(340, strlen((string) $roundTrip->rows()[0][2]));
                self::assertSame(400, $roundTrip->variables()[2]->width);

                $engine->write($tooWidePath, $this->envelopeFixture($format, 306));
                $tooWideTable = (new DoltProfile())->physicalIdentifier('dataset_' . $tooWideName);
                try {
                    $adapter->import($tooWidePath, $tooWideName);
                    self::fail('Expected 306 Dolt source variables to fail before DDL.');
                } catch (UnsupportedOperation $exception) {
                    self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
                }
                self::assertSame(0, (int) $this->scalar(
                    $pdo,
                    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    [$tooWideTable],
                ));
                self::assertSame(0, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM datasets WHERE dataset_name = ?', [$tooWideName]));
                $tooWideOperationId = $this->scalar(
                    $pdo,
                    'SELECT operation_id FROM operation_catalog WHERE target_path = ? ORDER BY started_at DESC LIMIT 1',
                    [$tooWidePath],
                );
                self::assertIsString($tooWideOperationId);
                self::assertSame('failed', $this->scalar(
                    $pdo,
                    'SELECT status FROM operation WHERE operation_id = ?',
                    [$tooWideOperationId],
                ));
                self::assertSame(1, (int) $this->scalar(
                    $pdo,
                    'SELECT COUNT(*) FROM fidelity_event WHERE operation_id = ? AND dataset_id IS NULL AND event_code = ?',
                    [$tooWideOperationId, DiagnosticCode::TargetCapabilityExceeded->value],
                ));
            } finally {
                $this->cleanup($pdo, $datasetName, $tableName);
                $this->cleanup($pdo, $tooWideName, null);
                $this->cleanupOperation($pdo, $tooWideOperationId);
                @unlink($sourcePath);
                @unlink($targetPath);
                @unlink($tooWidePath);
            }
        }
    }

    public function testInvalidWeightReferenceRejectsBeforeDdlAndPreservesFailureAudit(): void
    {
        $pdo = $this->mysql();
        $token = bin2hex(random_bytes(6));
        $datasetName = 'dolt post ddl failure ' . $token;
        $sourcePath = 'dolt-post-ddl-failure-' . $token . '.sav';
        $tableName = (new DoltProfile())->physicalIdentifier('dataset_' . $datasetName);
        $operationId = null;
        $fixture = new Dataset(
            new VariableDictionary([
                new VariableMetadata(
                    name: 'OnlyVariable',
                    type: VariableType::NUMERIC,
                    width: 0,
                    printFormat: new VariableFormat(5, 8, 2),
                    writeFormat: new VariableFormat(5, 8, 2),
                    dictionaryIndex: 1,
                ),
            ]),
            [[1.0]],
            new FileMetadata('Dolt pre-DDL invalid-weight fixture', weightVariableName: 'MissingWeight'),
            new FileTechnicalMetadata(sourceFormat: 'sav', compression: 1),
        );
        $adapter = new SpssAdapter($pdo, new FakeSpssEngine($fixture));

        try {
            $adapter->migrateCatalog();
            try {
                $adapter->import($sourcePath, $datasetName);
                self::fail('Expected the invalid weight reference to fail before wide-table DDL.');
            } catch (UnsupportedOperation $exception) {
                self::assertSame(DiagnosticCode::InvalidSourceDataset, $exception->diagnosticCode);
                self::assertStringContainsString('weight-variable reference', $exception->getMessage());
            }

            self::assertSame(0, (int) $this->scalar(
                $pdo,
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$tableName],
            ));
            self::assertSame(0, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM datasets WHERE dataset_name = ?', [$datasetName]));
            self::assertSame(0, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM variables WHERE dataset_name = ?', [$datasetName]));
            self::assertSame(0, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM dataset WHERE dataset_name = ?', [$datasetName]));

            $operationId = $this->scalar(
                $pdo,
                'SELECT operation_id FROM operation_catalog WHERE target_path = ? ORDER BY started_at DESC LIMIT 1',
                [$sourcePath],
            );
            self::assertIsString($operationId);
            self::assertNotSame('', $operationId);
            self::assertSame('failed', $this->scalar($pdo, 'SELECT status FROM operation WHERE operation_id = ?', [$operationId]));
            self::assertSame(1, (int) $this->scalar(
                $pdo,
                'SELECT COUNT(*) FROM fidelity_event WHERE operation_id = ? AND dataset_id IS NULL AND direction = ? AND severity = ? AND event_code = ? AND source_item = ?',
                [$operationId, 'import', 'error', DiagnosticCode::InvalidSourceDataset->value, $sourcePath],
            ));
        } finally {
            $this->cleanup($pdo, $datasetName, $tableName);
            if (is_string($operationId) && $operationId !== '') {
                $pdo->prepare('DELETE FROM fidelity_event WHERE operation_id = ?')->execute([$operationId]);
                $pdo->prepare('DELETE FROM fidelity_event_catalog WHERE operation_id = ?')->execute([$operationId]);
                $pdo->prepare('DELETE FROM operation_catalog WHERE operation_id = ?')->execute([$operationId]);
                $pdo->prepare('DELETE FROM operation WHERE operation_id = ?')->execute([$operationId]);
            }
        }
    }

    public function testObservedDoltStorageAndIdentifierBoundaries(): void
    {
        $pdo = $this->mysql();
        $token = bin2hex(random_bytes(6));
        $identifier64 = 'i' . str_repeat('a', 63);
        $identifier65 = 'i' . str_repeat('a', 64);
        $boundaryTable = 'oss_dolt_boundary_' . $token;
        $columnsTable = 'oss_dolt_columns_' . $token;
        $tooLargeDataset = 'dolt too large row ' . $token;
        $tooLargeTable = (new DoltProfile())->physicalIdentifier('dataset_' . $tooLargeDataset);
        $text = str_repeat("\xC3\xB5", 32_752);
        self::assertSame(65_504, strlen($text));

        try {
            $pdo->exec('CREATE TABLE ' . $this->quote($identifier64) . ' (id BIGINT NOT NULL PRIMARY KEY)');
            self::assertSame(1, (int) $this->scalar(
                $pdo,
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$identifier64],
            ));

            try {
                $pdo->exec('CREATE TABLE ' . $this->quote($identifier65) . ' (id BIGINT NOT NULL PRIMARY KEY)');
                self::fail('Dolt accepted a 65-byte ASCII table identifier.');
            } catch (PDOException) {
                self::assertSame(0, (int) $this->scalar(
                    $pdo,
                    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    [$identifier65],
                ));
            }

            $columns = ['__case_ordinal BIGINT NOT NULL PRIMARY KEY'];
            for ($index = 1; $index <= 306; ++$index) {
                $columns[] = $this->quote(sprintf('c%03d', $index)) . ' DOUBLE NULL';
            }
            $pdo->exec('CREATE TABLE ' . $this->quote($columnsTable) . ' (' . implode(', ', $columns) . ')');
            self::assertSame(307, (int) $this->scalar(
                $pdo,
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$columnsTable],
            ));

            $pdo->exec(
                'CREATE TABLE ' . $this->quote($boundaryTable)
                . ' (__case_ordinal BIGINT NOT NULL PRIMARY KEY, numeric_value DOUBLE NOT NULL, text_value LONGTEXT NOT NULL)',
            );
            $statement = $pdo->prepare(
                'INSERT INTO ' . $this->quote($boundaryTable)
                . ' (__case_ordinal, numeric_value, text_value) VALUES (?, ?, ?)',
            );
            if ($statement === false) {
                throw new RuntimeException('Could not prepare Dolt boundary insert.');
            }
            $statement->execute([1, sprintf('%.17g', PHP_FLOAT_MAX), $text]);

            $query = $pdo->query(
                'SELECT numeric_value, text_value FROM ' . $this->quote($boundaryTable)
                . ' WHERE __case_ordinal = 1',
            );
            if ($query === false) {
                throw new RuntimeException('Could not read the Dolt boundary row.');
            }
            $stored = $query->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($stored);
            self::assertSame(Binary64::encode(PHP_FLOAT_MAX), Binary64::encode((float) $stored['numeric_value']));
            self::assertSame($text, $stored['text_value']);
            self::assertSame(65_504, strlen((string) $stored['text_value']));

            try {
                (new MySqlWideTableImporter($pdo, new DoltProfile()))->import([
                    'variables' => [['name' => 'payload', 'type' => 'string', 'width' => 65_505]],
                    'data' => [[str_repeat('a', 65_505)]],
                ], $tooLargeDataset);
                self::fail('Dolt accepted an encoded row above the 65,504-byte ceiling.');
            } catch (UnsupportedOperation $exception) {
                self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
            }
            self::assertSame(0, (int) $this->scalar(
                $pdo,
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$tooLargeTable],
            ));
            self::assertSame(0, (int) $this->scalar(
                $pdo,
                'SELECT COUNT(*) FROM datasets WHERE dataset_name = ?',
                [$tooLargeDataset],
            ));
        } finally {
            $this->cleanup($pdo, $tooLargeDataset, $tooLargeTable);
            foreach ([$identifier65, $identifier64, $columnsTable, $boundaryTable] as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($table));
            }
        }
    }

    public function testFinalizationFailureCompensatesCommittedDoltDatasetAndPreservesNullDatasetAudit(): void
    {
        $pdo = $this->mysql();
        $token = bin2hex(random_bytes(6));
        $datasetName = 'dolt finalization failure ' . $token;
        $sourcePath = 'dolt-finalization-failure-' . $token . '.sav';
        $tableName = (new DoltProfile())->physicalIdentifier('dataset_' . $datasetName);
        $operationId = null;
        $priorDatasetId = NormativeCatalog::uuid();
        $priorOperationId = NormativeCatalog::uuid();
        $priorFidelityEventId = NormativeCatalog::uuid();
        $priorPhysicalTable = 'prior_owned_' . $token;
        $adapter = new SpssAdapter(
            $pdo,
            new FakeSpssEngine($this->envelopeFixture('sav', 3)),
            beforeImportFinalization: static function (): void {
                throw new RuntimeException('Injected finalization failure.');
            },
        );

        try {
            $adapter->migrateCatalog();
            $pdo->prepare(
                'INSERT INTO dataset (dataset_id, spec_version, source_format, physical_table_name, dataset_name, source_case_count, imported_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            )->execute([
                $priorDatasetId, '1.0', 'sav',
                $priorPhysicalTable, $datasetName, 0, '2026-07-30 00:00:00',
            ]);
            $pdo->prepare(
                'INSERT INTO operation (operation_id, operation_kind, status, source_format, started_at, completed_at) VALUES (?, ?, ?, ?, ?, ?)',
            )->execute([
                $priorOperationId, 'import', 'failed', 'sav',
                '2026-07-30 00:00:00', '2026-07-30 00:00:01',
            ]);
            $pdo->prepare(
                'INSERT INTO fidelity_event (fidelity_event_id, operation_id, dataset_id, direction, severity, event_code, source_item, detail_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            )->execute([
                $priorFidelityEventId, $priorOperationId, $priorDatasetId,
                'import', 'error', 'prior_failure', 'prior.sav', '{}',
                '2026-07-30 00:00:01',
            ]);

            try {
                $adapter->import($sourcePath, $datasetName);
                self::fail('The injected finalization failure was not reported.');
            } catch (RuntimeException $exception) {
                self::assertSame('Injected finalization failure.', $exception->getMessage());
            }

            self::assertSame(0, (int) $this->scalar(
                $pdo,
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$tableName],
            ));
            self::assertSame(0, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM datasets WHERE dataset_name = ?', [$datasetName]));
            self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM dataset WHERE dataset_name = ?', [$datasetName]));
            self::assertSame($priorDatasetId, $this->scalar(
                $pdo,
                'SELECT dataset_id FROM dataset WHERE dataset_name = ?',
                [$datasetName],
            ));
            self::assertSame(1, (int) $this->scalar(
                $pdo,
                'SELECT COUNT(*) FROM fidelity_event WHERE fidelity_event_id = ? AND dataset_id = ? AND event_code = ?',
                [$priorFidelityEventId, $priorDatasetId, 'prior_failure'],
            ));
            $operationId = $this->scalar(
                $pdo,
                'SELECT operation_id FROM operation_catalog WHERE target_path = ? ORDER BY started_at DESC LIMIT 1',
                [$sourcePath],
            );
            self::assertIsString($operationId);
            self::assertSame('failed', $this->scalar(
                $pdo,
                'SELECT status FROM operation WHERE operation_id = ?',
                [$operationId],
            ));
            self::assertSame(1, (int) $this->scalar(
                $pdo,
                'SELECT COUNT(*) FROM fidelity_event WHERE operation_id = ? AND dataset_id IS NULL AND event_code = ?',
                [$operationId, 'operation_failed'],
            ));
        } finally {
            $this->cleanup($pdo, $datasetName, $tableName);
            $this->cleanupOperation($pdo, $operationId);
            $pdo->prepare('DELETE FROM fidelity_event WHERE fidelity_event_id = ?')->execute([$priorFidelityEventId]);
            $pdo->prepare('DELETE FROM operation WHERE operation_id = ?')->execute([$priorOperationId]);
            $pdo->prepare('DELETE FROM dataset WHERE dataset_id = ?')->execute([$priorDatasetId]);
        }
    }

    private function cleanupOperation(PDO $pdo, mixed $operationId): void
    {
        if (!is_string($operationId) || $operationId === '') {
            return;
        }
        foreach (['fidelity_event', 'fidelity_event_catalog', 'operation_catalog', 'operation'] as $table) {
            $statement = $pdo->prepare('DELETE FROM ' . $table . ' WHERE operation_id = ?');
            if ($statement !== false) {
                $statement->execute([$operationId]);
            }
        }
    }

    private function envelopeFixture(string $format, int $variableCount): Dataset
    {
        $variables = [
            new VariableMetadata(
                name: 'Weight',
                type: VariableType::NUMERIC,
                width: 0,
                printFormat: new VariableFormat(5, 8, 2),
                writeFormat: new VariableFormat(5, 8, 2),
                dictionaryIndex: 1,
            ),
            new VariableMetadata(
                name: 'ShortText',
                type: VariableType::STRING,
                width: 16,
                printFormat: new VariableFormat(1, 16),
                writeFormat: new VariableFormat(1, 16),
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
        ];
        $row = [1.25, 'short value', str_repeat("\xC3\xB5", 170)];
        for ($index = 4; $index <= $variableCount; ++$index) {
            $variables[] = new VariableMetadata(
                name: sprintf('V%03d', $index),
                type: VariableType::NUMERIC,
                width: 0,
                printFormat: new VariableFormat(5, 8, 2),
                writeFormat: new VariableFormat(5, 8, 2),
                dictionaryIndex: $index,
            );
            $row[] = (float) $index;
        }

        return new Dataset(
            new VariableDictionary($variables),
            [$row],
            new FileMetadata('Dolt 305-variable envelope fixture', weightVariableName: 'Weight'),
            new FileTechnicalMetadata(sourceFormat: $format, compression: $format === 'zsav' ? 2 : 1),
        );
    }

    protected function serviceName(): string
    {
        return 'dolt';
    }

    protected function environmentPrefix(): string
    {
        return 'OPENSTATSPEC_DOLT';
    }
}
