<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\CanonicalWideTableExporter;
use OpenStatSpec\Sql\MySqlWideTableExporter;
use OpenStatSpec\Tests\Support\ExportCountingPdo;
use OpenStatSpec\Sql\MySqlProfile;
use OpenStatSpec\Sql\MySqlWideTableImporter;
use OpenStatSpec\Spss\PhpSpssEngine;
use OpenStatSpec\Spss\SpssAdapter;
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
 * Runs only against an explicitly configured MySQL-family instance.
 *
 * GitHub Actions supplies the service. Developers can opt in locally with:
 * The concrete MySQL and MariaDB subclasses each supply their own DSN variables.
 */
abstract class MySqlFamilySpssRoundTripTestCase extends TestCase
{
    use VariableCatalogAssertions;
    public function testRealEngineRoundTripsSavAndZsavThroughMySqlFamily(): void
    {
        $pdo = $this->mysql();
        self::assertInstanceOf(ExportCountingPdo::class, $pdo);
        $engine = new PhpSpssEngine();

        foreach (['sav' => ['$FL2', 1], 'zsav' => ['$FL3', 2]] as $format => [$header, $compression]) {
            $token = bin2hex(random_bytes(6));
            $datasetName = $this->serviceName() . ' integration ' . $format . ' ' . $token;
            $sourcePath = sys_get_temp_dir() . '/openstatspec-mysql-source-' . $token . '.' . $format;
            $targetPath = sys_get_temp_dir() . '/openstatspec-mysql-target-' . $token . '.' . $format;
            $tableName = null;

            try {
                $fixture = $this->fixture($format);
                $engine->write($sourcePath, $fixture);
                self::assertSame($header, $this->fileHeader($sourcePath));

                $adapter = new SpssAdapter($pdo, $engine);
                $adapter->import($sourcePath, $datasetName);
                $tableName = $this->tableName($pdo, $datasetName);

                self::assertMatchesRegularExpression('/^dataset_/', $tableName);
                $caseCount = $pdo->query('SELECT COUNT(*) FROM ' . $this->quote($tableName));
                if ($caseCount === false) {
                    throw new RuntimeException('Could not count imported MySQL-family cases.');
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

                $columnRows = $this->rows($pdo, 'SELECT source_name, column_name FROM variables WHERE dataset_name = ? ORDER BY ordinal', [$datasetName]);
                $columns = [];
                foreach ($columnRows as $column) {
                    $columns[(string) $column['source_name']] = (string) $column['column_name'];
                }
                $wideRows = $this->rows(
                    $pdo,
                    'SELECT ' . $this->quote('__case_ordinal') . ', ' . $this->quote($columns['Score']) . ', '
                    . $this->quote($columns['Reason']) . ', ' . $this->quote($columns['LongText'])
                    . ' FROM ' . $this->quote($tableName) . ' ORDER BY ' . $this->quote('__case_ordinal'),
                    [],
                );
                self::assertCount(2, $wideRows);
                self::assertSame('1', (string) $wideRows[0]['__case_ordinal']);
                self::assertEquals(7.5, (float) $wideRows[0][$columns['Score']]);
                self::assertSame('present', $wideRows[0][$columns['Reason']]);
                self::assertSame(340, strlen((string) $wideRows[0][$columns['LongText']]));
                self::assertSame('2', (string) $wideRows[1]['__case_ordinal']);
                self::assertNull($wideRows[1][$columns['Score']], 'SPSS numeric system-missing must persist as SQL NULL.');
                self::assertSame('MISSING', $wideRows[1][$columns['Reason']], 'SPSS user-missing values must remain raw SQL values.');

                $display = $this->rows($pdo, 'SELECT measurement_level, display_width, alignment FROM variable_display_metadata WHERE dataset_name = ? AND variable_ordinal = 1', [$datasetName]);
                self::assertSame(Measure::SCALE->value, (int) $display[0]['measurement_level']);
                self::assertSame(12, (int) $display[0]['display_width']);
                self::assertSame(Alignment::RIGHT->value, (int) $display[0]['alignment']);
                $technical = $this->rows($pdo, 'SELECT source_format, compression FROM file_technical_metadata WHERE dataset_name = ?', [$datasetName]);
                self::assertSame($format, $technical[0]['source_format']);
                self::assertSame($compression, (int) $technical[0]['compression']);

                $executionCounts = [];
                foreach ([CanonicalWideTableExporter::class, MySqlWideTableExporter::class] as $exporterClass) {
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
                self::assertSame('MySQL-family integration fixture', $roundTrip->metadata->label);
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
                self::assertEquals([new FileAttribute('Source', ['MySQL-family integration'])], $roundTrip->metadata->attributes());
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

    /** @return iterable<string, array{bool, string}> */
    public static function batchImports(): iterable
    {
        foreach (['native' => false, 'emulated' => true] as $mode => $emulated) {
            foreach (['mixed', 'wide NULL', 'escaped packets', 'late failure'] as $shape) {
                yield "$mode $shape" => [$emulated, $shape];
            }
        }
    }

    #[DataProvider('batchImports')]
    public function testBoundedCaseImportOnServer(bool $emulated, string $shape): void
    {
        $pdo = $this->mysql();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);
        $profile = (new Connection($pdo))->profile;
        self::assertInstanceOf(MySqlProfile::class, $profile);
        $token = bin2hex(random_bytes(6));
        $priorName = 'batch prior ' . $token;
        $attemptName = 'batch attempt ' . $token;
        $priorTable = $profile->physicalIdentifier('dataset_' . $priorName);
        $attemptTable = $profile->physicalIdentifier('dataset_' . $attemptName);
        try {
            // Keep InnoDB's inline row below a page while exceeding the parameter limit.
            $width = $shape === 'wide NULL' ? min(511, $profile->maximumSourceVariables()) : 2;
            $variables = $shape === 'wide NULL'
                ? array_map(static fn(int $i): array => ['name' => 'v' . $i, 'type' => 'numeric'], range(1, $width))
                : [['name' => 'Score', 'type' => 'numeric'], ['name' => 'Comment', 'type' => 'string']];
            $count = $shape === 'wide NULL' ? 2 * intdiv(65535, $width + 1) + 1 : 513;
            $text = '';
            if ($shape === 'escaped packets') {
                $budget = $profile->effectiveMaximumStatementBytes($pdo);
                self::assertGreaterThan(8, $budget, 'The dedicated service must allow a preflight-valid row.');
                $text = str_repeat("'\\\\", intdiv(min(50000, $budget - 8), 3));
                $count = 41;
            }
            $rows = [];
            for ($i = 0; $i < $count; ++$i) {
                $rows[] = $shape === 'wide NULL' ? array_fill(0, $width, null)
                    : [[0.1, null, 42, 1.0000000000000002, PHP_FLOAT_MAX, 5.0e-324][$i % 6], $shape === 'escaped packets' ? $text : ($i % 2 === 0 ? "õ'\\\\ $i" : '')];
            }
            $importer = new MySqlWideTableImporter($pdo, $profile);
            $importer->import(['variables' => $variables, 'data' => [$rows[0]]], $priorName);
            $prior = $this->rows($pdo, 'SELECT * FROM ' . $this->quote($priorTable), []);
            if ($shape === 'late failure') {
                // Real server CHECK failure on row 257, confined to this attempt's DDL.
                $faultPdo = $this->createMock(PDO::class);
                foreach (['getAttribute', 'setAttribute', 'inTransaction', 'beginTransaction', 'commit', 'rollBack', 'query', 'prepare'] as $method) {
                    $faultPdo->method($method)->willReturnCallback($pdo->$method(...));
                }
                $faultPdo->method('exec')->willReturnCallback(function (string $sql) use ($pdo, $attemptTable, $token): int|false {
                    $result = $pdo->exec($sql);
                    if (str_starts_with($sql, 'CREATE TABLE ' . $this->quote($attemptTable) . ' ')) {
                        $pdo->exec('ALTER TABLE ' . $this->quote($attemptTable) . ' ADD CONSTRAINT ' . $this->quote('reject_batch_tail_' . $token) . ' CHECK (__case_ordinal < 257)');
                    }
                    return $result;
                });
                try {
                    (new MySqlWideTableImporter($faultPdo, $profile))->import(['variables' => $variables, 'data' => $rows], $attemptName);
                    self::fail('The server accepted the forbidden tail row.');
                } catch (PDOException $exception) {
                    self::assertStringContainsString('reject_batch_tail', $exception->getMessage());
                }
                self::assertSame(0, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$attemptTable]));
                self::assertSame(0, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM datasets WHERE dataset_name = ?', [$attemptName]));
                self::assertSame(0, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM variables WHERE dataset_name = ?', [$attemptName]));
            } else {
                $definition = $importer->import(['variables' => $variables, 'data' => $rows], $attemptName);
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
            self::assertSame($prior, $this->rows($pdo, 'SELECT * FROM ' . $this->quote($priorTable), []));
            self::assertFalse($pdo->inTransaction());
            self::assertSame($emulated, $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->cleanup($pdo, $attemptName, $attemptTable);
            $this->cleanup($pdo, $priorName, $priorTable);
        }
    }

    abstract protected function serviceName(): string;

    abstract protected function environmentPrefix(): string;

    protected function mysql(): PDO
    {
        $prefix = $this->environmentPrefix();
        $dsn = getenv($prefix . '_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped(sprintf('Set %s_DSN to run %s integration tests.', $prefix, $this->serviceName()));
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO mysql is not available in this PHP environment.');
        }

        $user = getenv($prefix . '_USER');
        $password = getenv($prefix . '_PASSWORD');

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
                'MySQL-family integration fixture',
                weightVariableName: 'Score',
                documents: ['First document line', 'Second document line'],
                attributes: [new FileAttribute('Source', ['MySQL-family integration'])],
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

    protected function tableName(PDO $pdo, string $datasetName): string
    {
        $statement = $pdo->prepare('SELECT table_name FROM datasets WHERE dataset_name = ?');
        $statement->execute([$datasetName]);
        $tableName = $statement->fetchColumn();
        if (!is_string($tableName) || $tableName === '') {
            throw new RuntimeException('The MySQL-family dataset catalogue entry was not created.');
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
    protected function scalar(PDO $pdo, string $sql, array $parameters): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    protected function cleanup(PDO $pdo, string $datasetName, ?string $tableName): void
    {
        if ($tableName !== null) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($tableName));
        }
        foreach ([
            'dataset_weight_variables',
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

    protected function quote(string $identifier): string
    {
        return chr(96) . str_replace(chr(96), chr(96) . chr(96), $identifier) . chr(96);
    }

    protected function fileHeader(string $path): string
    {
        $header = file_get_contents($path, false, null, 0, 4);
        if (!is_string($header)) {
            throw new RuntimeException('Could not read the SPSS file header.');
        }

        return $header;
    }
}
