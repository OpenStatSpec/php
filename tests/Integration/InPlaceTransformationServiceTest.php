<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\ServerVersionPolicy;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Transformation\Execution\InPlaceTransformationExecutor;
use OpenStatSpec\Transformation\Model\Action\AssignValueAction;
use OpenStatSpec\Transformation\Model\Action\CopySourceAction;
use OpenStatSpec\Transformation\Model\RecodeOperation;
use OpenStatSpec\Transformation\Model\RecodeRule;
use OpenStatSpec\Transformation\Model\ScalarValue;
use OpenStatSpec\Transformation\Model\Selector\ElseSelector;
use OpenStatSpec\Transformation\Model\Selector\ExactValueSelector;
use OpenStatSpec\Transformation\Model\Selector\MissingValueSelector;
use OpenStatSpec\Transformation\Model\Selector\NumericRangeSelector;
use OpenStatSpec\Transformation\Model\SetValueLabelsOperation;
use OpenStatSpec\Transformation\Model\SetVariableLabelOperation;
use OpenStatSpec\Transformation\Model\TransformationPlan;
use OpenStatSpec\Transformation\Model\ValueLabel;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InPlaceTransformationServiceTest extends TestCase
{
    private const DATASET_ID = '018f47f2-8b6a-7c3d-9e1f-123456789abc';
    private const SOURCE_VARIABLE_ID = '018f47f2-8b6a-7c3d-9e1f-123456789abd';
    private const DESTINATION_VARIABLE_ID = '018f47f2-8b6a-7c3d-9e1f-123456789abe';
    private const IMPORTED_AT = '2026-08-12 00:00:00';

    private int $fixtureSequence = 0;

    /** @var array{dataset_id: string, source_variable_id: string, destination_variable_id: string, table_name: string, dataset_name: string}|null */
    private ?array $activeFixture = null;

    /** @return iterable<string, array{string, string|null, string, list<string>, string|null}> */
    public static function services(): iterable
    {
        yield 'SQLite' => ['sqlite', null, 'sqlite', ['>=3.24.0 <4.0.0'], null];
        yield 'PostgreSQL' => ['postgresql', 'OPENSTATSPEC_PG', 'pgsql', ['17.x', '18.x'], 'OPENSTATSPEC_EXPECTED_POSTGRES_VERSION'];
        yield 'MySQL' => ['mysql', 'OPENSTATSPEC_MYSQL', 'mysql', ['8.4.x', '9.7.x'], 'OPENSTATSPEC_EXPECTED_MYSQL_VERSION'];
        yield 'MariaDB' => ['mariadb', 'OPENSTATSPEC_MARIADB', 'mysql', ['11.4.x', '11.8.x', '12.3.x'], 'OPENSTATSPEC_EXPECTED_MARIADB_VERSION'];
        yield 'Dolt' => ['dolt', 'OPENSTATSPEC_DOLT', 'mysql', ['2.2.x'], 'OPENSTATSPEC_EXPECTED_DOLT_VERSION'];
    }

    /** @return iterable<string, array{string, string|null, string, list<string>, string|null}> */
    public static function createTargetCapableServices(): iterable
    {
        foreach (self::services() as $label => $service) {
            if (in_array($service[0], ['sqlite', 'postgresql'], true)) {
                yield $label => $service;
            }
        }
    }

    /** @return iterable<string, array{string, string|null, string, list<string>, string|null}> */
    public static function createTargetRejectedServices(): iterable
    {
        foreach (self::services() as $label => $service) {
            if (in_array($service[0], ['mysql', 'mariadb', 'dolt'], true)) {
                yield $label => $service;
            }
        }
    }

    /** @param list<string> $expectedVersionFamilies */
    #[DataProvider('services')]
    public function testConfiguredServiceMatchesExpectedProfileAndVersionFamily(
        string $expectedProfile,
        ?string $environmentPrefix,
        string $driver,
        array $expectedVersionFamilies,
        ?string $expectedVersionEnvironment,
    ): void {
        $connection = new Connection($this->servicePdo($expectedProfile, $environmentPrefix, $driver));

        self::assertSame($expectedProfile, $connection->profileName);
        self::assertTrue($connection->claimedSupported);
        $this->assertExpectedVersionFamily($connection, $expectedVersionFamilies, $expectedVersionEnvironment);
    }

    public function testFixtureInstallationsUseDistinctDeterministicNamespaces(): void
    {
        $pdo = $this->servicePdo('sqlite', null, 'sqlite');
        $connection = new Connection($pdo);
        $first = $this->installFixture($pdo, $connection);
        $second = null;

        try {
            try {
                $second = $this->installFixture($pdo, $connection);
            } catch (RuntimeException $exception) {
                self::fail('A second independent fixture installation collided: ' . $exception->getMessage());
            }

            self::assertNotSame($first['dataset_id'], $second['dataset_id']);
            self::assertNotSame($first['table_name'], $second['table_name']);
            self::assertSame(2, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM dataset', []));
        } finally {
            if (is_array($second)) {
                $this->purgeFixture($pdo, $connection, $second);
            }
            $this->purgeFixture($pdo, $connection, $first);
        }
    }

    /** @param list<string> $expectedVersionFamilies */
    #[DataProvider('services')]
    public function testExistingTargetTransformationPreservesPhysicalIdentityInPlace(
        string $expectedProfile,
        ?string $environmentPrefix,
        string $driver,
        array $expectedVersionFamilies,
        ?string $expectedVersionEnvironment,
    ): void {
        unset($expectedVersionFamilies, $expectedVersionEnvironment);
        $pdo = $this->servicePdo($expectedProfile, $environmentPrefix, $driver);
        $connection = new Connection($pdo);
        $fixture = $this->installFixture($pdo, $connection);

        try {
            self::assertSame(self::DATASET_ID, $fixture['dataset_id']);
            $plan = $this->existingTargetPlan();
            $result = (new InPlaceTransformationExecutor($connection))->execute($plan);

            self::assertSame($fixture['dataset_id'], $result->datasetId());
            self::assertSame($plan->hash(), $result->planHash());
            self::assertSame(3, $result->operationCount());

            self::assertSame($fixture['dataset'], $this->datasetRow($pdo));
            self::assertSame($fixture['variables'], $this->variableIdentityRows($pdo));
            self::assertSame($fixture['columns'], $this->tableColumns($pdo, $connection, $fixture['table_name']));
            self::assertSame($fixture['tables'], $this->tableNames($pdo));

            self::assertSame(
                [10.0, 20.0, 20.0, 9.0, 99.0],
                $this->numericColumn(
                    $pdo,
                    $connection,
                    $fixture['table_name'],
                    'destination',
                ),
            );
            self::assertSame(
                [
                    [
                        'variable_id' => $fixture['destination_variable_id'],
                        'variable_label' => 'Recoded destination',
                    ],
                ],
                $this->rows(
                    $pdo,
                    'SELECT variable_id, variable_label FROM variable WHERE dataset_id = ? AND source_name = ?',
                    [$fixture['dataset_id'], 'Destination'],
                ),
            );
            self::assertSame(
                [
                    ['ordinal' => 1, 'code_kind' => 'numeric', 'value' => 10.0, 'label' => 'Ten'],
                    ['ordinal' => 2, 'code_kind' => 'numeric', 'value' => 20.0, 'label' => 'Twenty'],
                    ['ordinal' => 3, 'code_kind' => 'numeric', 'value' => 99.0, 'label' => 'Missing source'],
                ],
                $this->destinationValueLabels($pdo),
            );
            self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM dataset WHERE dataset_id = ?', [$fixture['dataset_id']]));
            self::assertSame(2, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM variable WHERE dataset_id = ?', [$fixture['dataset_id']]));
            $this->assertNoArtifactTables($fixture['tables']);
        } finally {
            $this->purgeFixture($pdo, $connection, $fixture);
        }
    }

    /** @param list<string> $expectedVersionFamilies */
    #[DataProvider('createTargetCapableServices')]
    public function testCreateTargetTransformationCreatesNumericTargetInPlace(
        string $expectedProfile,
        ?string $environmentPrefix,
        string $driver,
        array $expectedVersionFamilies,
        ?string $expectedVersionEnvironment,
    ): void {
        unset($expectedVersionFamilies, $expectedVersionEnvironment);
        $pdo = $this->servicePdo($expectedProfile, $environmentPrefix, $driver);
        $connection = new Connection($pdo);
        $fixture = $this->installFixture($pdo, $connection);

        try {
            $plan = $this->createTargetPlan();
            $result = (new InPlaceTransformationExecutor($connection))->execute($plan);

            self::assertSame($fixture['dataset_id'], $result->datasetId());
            self::assertSame($plan->hash(), $result->planHash());
            self::assertSame(3, $result->operationCount());

            self::assertSame($fixture['dataset'], $this->datasetRow($pdo));
            self::assertSame($fixture['tables'], $this->tableNames($pdo));
            self::assertSame(
                [...$fixture['columns'], 'createdtarget'],
                $this->tableColumns($pdo, $connection, $fixture['table_name']),
            );
            self::assertSame(
                [
                    [
                        '__case_ordinal' => 1,
                        'source_value' => 1.0,
                        'destination' => -1.0,
                        'createdtarget' => 10.0,
                    ],
                    [
                        '__case_ordinal' => 2,
                        'source_value' => 2.0,
                        'destination' => -1.0,
                        'createdtarget' => 20.0,
                    ],
                    [
                        '__case_ordinal' => 3,
                        'source_value' => 3.0,
                        'destination' => -1.0,
                        'createdtarget' => 20.0,
                    ],
                    [
                        '__case_ordinal' => 4,
                        'source_value' => 9.0,
                        'destination' => -1.0,
                        'createdtarget' => 9.0,
                    ],
                    [
                        '__case_ordinal' => 5,
                        'source_value' => null,
                        'destination' => -1.0,
                        'createdtarget' => 99.0,
                    ],
                ],
                $this->tableRows(
                    $pdo,
                    $connection,
                    $fixture['table_name'],
                    ['__case_ordinal', 'source_value', 'destination', 'createdtarget'],
                ),
            );
            self::assertSame($fixture['variables'], array_slice($this->variableIdentityRows($pdo), 0, 2));
            self::assertSame(3, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM variable WHERE dataset_id = ?', [$fixture['dataset_id']]));
            $createdTargetRows = $this->rows(
                $pdo,
                'SELECT variable_id, source_ordinal, source_name, physical_name, storage_kind, declared_string_width, variable_label '
                . 'FROM variable WHERE dataset_id = ? AND source_name = ?',
                [$fixture['dataset_id'], 'CreatedTarget'],
            );
            self::assertCount(1, $createdTargetRows);
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', (string) $createdTargetRows[0]['variable_id']);
            self::assertSame(
                [
                    'source_ordinal' => 3,
                    'source_name' => 'CreatedTarget',
                    'physical_name' => 'createdtarget',
                    'storage_kind' => 'numeric',
                    'declared_string_width' => null,
                    'variable_label' => 'Recoded created target',
                ],
                [
                    'source_ordinal' => (int) $createdTargetRows[0]['source_ordinal'],
                    'source_name' => (string) $createdTargetRows[0]['source_name'],
                    'physical_name' => (string) $createdTargetRows[0]['physical_name'],
                    'storage_kind' => (string) $createdTargetRows[0]['storage_kind'],
                    'declared_string_width' => $createdTargetRows[0]['declared_string_width'],
                    'variable_label' => (string) $createdTargetRows[0]['variable_label'],
                ],
            );
            self::assertSame(
                [
                    ['ordinal' => 1, 'code_kind' => 'numeric', 'value' => 10.0, 'label' => 'Ten'],
                    ['ordinal' => 2, 'code_kind' => 'numeric', 'value' => 20.0, 'label' => 'Twenty'],
                    ['ordinal' => 3, 'code_kind' => 'numeric', 'value' => 99.0, 'label' => 'Missing source'],
                ],
                $this->valueLabelsForVariable($pdo, 'CreatedTarget'),
            );
            self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM dataset WHERE dataset_id = ?', [$fixture['dataset_id']]));
            $this->assertNoArtifactTables($fixture['tables']);
        } finally {
            $this->purgeFixture($pdo, $connection, $fixture);
        }
    }

    /** @param list<string> $expectedVersionFamilies */
    #[DataProvider('createTargetRejectedServices')]
    public function testCreateTargetTransformationRejectsNonAtomicProfilesWithoutChangingState(
        string $expectedProfile,
        ?string $environmentPrefix,
        string $driver,
        array $expectedVersionFamilies,
        ?string $expectedVersionEnvironment,
    ): void {
        unset($expectedVersionFamilies, $expectedVersionEnvironment);
        $pdo = $this->servicePdo($expectedProfile, $environmentPrefix, $driver);
        $connection = new Connection($pdo);
        $fixture = $this->installFixture($pdo, $connection);

        try {
            $before = [
                'dataset' => $this->datasetRow($pdo),
                'variables' => $this->variableIdentityRows($pdo),
                'value_label_sets' => $this->valueLabelSetRows($pdo),
                'value_labels' => $this->valueLabelRows($pdo),
                'variable_value_label_sets' => $this->variableValueLabelSetRows($pdo),
                'rows' => $this->tableRows(
                    $pdo,
                    $connection,
                    $fixture['table_name'],
                    ['__case_ordinal', 'source_value', 'destination'],
                ),
                'columns' => $this->tableColumns($pdo, $connection, $fixture['table_name']),
                'tables' => $this->tableNames($pdo),
                'dataset_count' => (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM dataset', []),
                'variable_count' => (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM variable', []),
            ];

            try {
                (new InPlaceTransformationExecutor($connection))->execute($this->createTargetPlan());
                self::fail('Expected non-atomic service profile to reject implicit target creation during preflight.');
            } catch (UnsupportedOperation $exception) {
                self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
                self::assertSame(
                    sprintf(
                        '%s cannot atomically add a new INTO target to an existing wide table; register the target variable first.',
                        $expectedProfile,
                    ),
                    $exception->getMessage(),
                );
            }

            self::assertSame($before['dataset'], $this->datasetRow($pdo));
            self::assertSame($before['variables'], $this->variableIdentityRows($pdo));
            self::assertSame($before['value_label_sets'], $this->valueLabelSetRows($pdo));
            self::assertSame($before['value_labels'], $this->valueLabelRows($pdo));
            self::assertSame($before['variable_value_label_sets'], $this->variableValueLabelSetRows($pdo));
            self::assertSame(
                $before['rows'],
                $this->tableRows(
                    $pdo,
                    $connection,
                    $fixture['table_name'],
                    ['__case_ordinal', 'source_value', 'destination'],
                ),
            );
            self::assertSame($before['columns'], $this->tableColumns($pdo, $connection, $fixture['table_name']));
            self::assertSame($before['tables'], $this->tableNames($pdo));
            self::assertSame($before['dataset_count'], (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM dataset', []));
            self::assertSame($before['variable_count'], (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM variable', []));
            $this->assertNoArtifactTables($fixture['tables']);
        } finally {
            $this->purgeFixture($pdo, $connection, $fixture);
        }
    }

    /** @param list<string> $expectedVersionFamilies */
    #[DataProvider('services')]
    public function testFixtureSetupRejectsDirtyDeterministicNamespaceWithoutDeletingIt(
        string $expectedProfile,
        ?string $environmentPrefix,
        string $driver,
        array $expectedVersionFamilies,
        ?string $expectedVersionEnvironment,
    ): void {
        unset($expectedVersionFamilies, $expectedVersionEnvironment);
        $pdo = $this->servicePdo($expectedProfile, $environmentPrefix, $driver);
        $connection = new Connection($pdo);
        $fixture = $this->reserveFixtureIdentity($connection);
        $tableName = $fixture['table_name'];

        $this->assertDoltWorkingSetCleanBeforeFixture($pdo, $connection);
        (new NormativeCatalog($pdo))->createTables();
        CatalogOwnership::markCurrentVersion($pdo);
        $pdo->exec(
            'CREATE TABLE ' . $this->qualifiedTable($connection, $tableName)
            . ' (' . $connection->profile->quoteIdentifier('__case_ordinal') . ' BIGINT NOT NULL PRIMARY KEY)',
        );
        $pdo->prepare(
            'INSERT INTO dataset '
            . '(dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, dataset_name, source_case_count, imported_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$fixture['dataset_id'], '1.0', 'fixture', null, $tableName, 'preexisting deterministic namespace', 0, self::IMPORTED_AT]);
        $this->commitDoltFixture($pdo, $connection, $fixture);

        try {
            try {
                $this->installFixture($pdo, $connection, $fixture);
                self::fail('Dirty deterministic fixture namespace was silently deleted.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('deterministic fixture namespace is not clean', $exception->getMessage());
            }

            self::assertSame(
                'preexisting deterministic namespace',
                $this->scalar($pdo, 'SELECT dataset_name FROM dataset WHERE dataset_id = ?', [$fixture['dataset_id']]),
            );
            self::assertContains($tableName, $this->tableNames($pdo));
        } finally {
            $this->purgeDirtyNamespaceFixture($pdo, $connection, $fixture);
        }
    }

    /** @param list<string> $expectedVersionFamilies */
    private function assertExpectedVersionFamily(
        Connection $connection,
        array $expectedVersionFamilies,
        ?string $expectedVersionEnvironment,
    ): void {
        $version = $connection->serverVersion;
        $claim = ServerVersionPolicy::assess($connection->profileName, $version);
        self::assertSame(ServerVersionPolicy::claim($connection->profileName), $claim['matched_claim']);

        if ($connection->profileName === 'sqlite') {
            self::assertSame(['>=3.24.0 <4.0.0'], $expectedVersionFamilies);
            return;
        }

        $activeFamily = $this->versionFamily($connection->profileName, $version);
        self::assertContains($activeFamily, $expectedVersionFamilies);

        if ($expectedVersionEnvironment !== null) {
            $expectedVersion = getenv($expectedVersionEnvironment);
            if (is_string($expectedVersion) && $expectedVersion !== '') {
                self::assertSame(
                    $this->versionFamily($connection->profileName, $expectedVersion),
                    $activeFamily,
                    $expectedVersionEnvironment . ' must match by claimed .x family, not patch.',
                );
            }
        }
    }

    private function versionFamily(string $profile, string $version): string
    {
        $normalized = ServerVersionPolicy::normalize($profile, $version);
        self::assertIsString($normalized, $profile . ': normalizable server version');
        self::assertMatchesRegularExpression('/^\d+\.\d+(?:\.\d+)?$/', $normalized);
        $parts = explode('.', $normalized);

        return $profile === 'postgresql'
            ? $parts[0] . '.x'
            : $parts[0] . '.' . $parts[1] . '.x';
    }

    /**
     * @return array{
     *     dataset: array<string, mixed>,
     *     variables: list<array<string, mixed>>,
     *     columns: list<string>,
     *     tables: list<string>,
     *     table_name: string
     * }
     */
    /**
     * @param array{dataset_id: string, source_variable_id: string, destination_variable_id: string, table_name: string, dataset_name: string}|null $fixture
     * @return array{dataset: array<string, mixed>, variables: list<array<string, mixed>>, columns: list<string>, tables: list<string>, dataset_id: string, source_variable_id: string, destination_variable_id: string, table_name: string, dataset_name: string}
     */
    private function installFixture(PDO $pdo, Connection $connection, ?array $fixture = null): array
    {
        $this->assertDoltWorkingSetCleanBeforeFixture($pdo, $connection);
        (new NormativeCatalog($pdo))->createTables();
        CatalogOwnership::markCurrentVersion($pdo);

        $fixture ??= $this->reserveFixtureIdentity($connection);
        $this->assertFixtureNamespaceClean($pdo, $connection, $fixture);
        $this->activeFixture = $fixture;

        $tableName = $fixture['table_name'];

        $quotedTable = $this->qualifiedTable($connection, $tableName);
        $quotedOrdinal = $connection->profile->quoteIdentifier('__case_ordinal');
        $quotedSource = $connection->profile->quoteIdentifier('source_value');
        $quotedDestination = $connection->profile->quoteIdentifier('destination');

        $pdo->exec(
            'CREATE TABLE ' . $quotedTable . ' ('
            . $quotedOrdinal . ' BIGINT NOT NULL PRIMARY KEY, '
            . $quotedSource . ' ' . $connection->profile->numericType() . ' NULL, '
            . $quotedDestination . ' ' . $connection->profile->numericType() . ' NULL'
            . ')',
        );

        $pdo->prepare(
            'INSERT INTO dataset '
            . '(dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, dataset_name, source_case_count, imported_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $fixture['dataset_id'],
            '1.0',
            'fixture',
            null,
            $tableName,
            $fixture['dataset_name'],
            5,
            self::IMPORTED_AT,
        ]);

        $insertVariable = $pdo->prepare(
            'INSERT INTO variable '
            . '(variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
        );
        $insertVariable->execute([
            $fixture['source_variable_id'],
            $fixture['dataset_id'],
            1,
            'SourceValue',
            'source_value',
            'numeric',
        ]);
        $insertVariable->execute([
            $fixture['destination_variable_id'],
            $fixture['dataset_id'],
            2,
            'Destination',
            'destination',
            'numeric',
        ]);

        $insertCase = $pdo->prepare(
            'INSERT INTO ' . $quotedTable . ' (' . $quotedOrdinal . ', ' . $quotedSource . ', ' . $quotedDestination . ') VALUES (?, ?, ?)',
        );
        foreach ([[1, 1.0, -1.0], [2, 2.0, -1.0], [3, 3.0, -1.0], [4, 9.0, -1.0], [5, null, -1.0]] as $row) {
            $insertCase->execute($row);
        }

        $this->commitDoltFixture($pdo, $connection, $fixture);

        return [
            'dataset' => $this->datasetRow($pdo),
            'variables' => $this->variableIdentityRows($pdo),
            'columns' => $this->tableColumns($pdo, $connection, $tableName),
            'tables' => $this->tableNames($pdo),
            ...$fixture,
        ];
    }

    /** @param array{dataset_id: string, source_variable_id: string, destination_variable_id: string, table_name: string, dataset_name: string} $fixture */
    private function purgeFixture(PDO $pdo, Connection $connection, array $fixture): void
    {
        if ($connection->profileName === 'dolt') {
            return;
        }

        (new NormativeCatalog($pdo))->createTables();

        $dataset = $this->rows(
            $pdo,
            'SELECT physical_table_name, dataset_name, source_format, source_case_count FROM dataset WHERE dataset_id = ?',
            [$fixture['dataset_id']],
        );
        if ($dataset !== []) {
            self::assertCount(1, $dataset);
            self::assertSame([
                'physical_table_name' => $fixture['table_name'],
                'dataset_name' => $fixture['dataset_name'],
                'source_format' => 'fixture',
                'source_case_count' => 5,
            ], [
                'physical_table_name' => (string) $dataset[0]['physical_table_name'],
                'dataset_name' => (string) $dataset[0]['dataset_name'],
                'source_format' => (string) $dataset[0]['source_format'],
                'source_case_count' => (int) $dataset[0]['source_case_count'],
            ]);
        }

        $pdo->prepare(
            'DELETE FROM variable_value_label_set WHERE variable_id IN (SELECT variable_id FROM variable WHERE dataset_id = ?)',
        )->execute([$fixture['dataset_id']]);
        $pdo->prepare(
            'DELETE FROM value_label WHERE value_label_set_id IN (SELECT value_label_set_id FROM value_label_set WHERE dataset_id = ?)',
        )->execute([$fixture['dataset_id']]);
        $pdo->prepare('DELETE FROM value_label_set WHERE dataset_id = ?')->execute([$fixture['dataset_id']]);
        $pdo->prepare('DELETE FROM variable WHERE dataset_id = ?')->execute([$fixture['dataset_id']]);
        $pdo->prepare('DELETE FROM dataset WHERE dataset_id = ?')->execute([$fixture['dataset_id']]);
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->qualifiedTable($connection, $fixture['table_name']));
    }

    /** @param array{dataset_id: string, source_variable_id: string, destination_variable_id: string, table_name: string, dataset_name: string} $fixture */
    private function assertFixtureNamespaceClean(PDO $pdo, Connection $connection, array $fixture): void
    {
        $existingRows = (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM dataset WHERE dataset_id = ? OR physical_table_name = ?', [$fixture['dataset_id'], $fixture['table_name']]);
        $existingVariables = (int) $this->scalar(
            $pdo,
            'SELECT COUNT(*) FROM variable WHERE variable_id IN (?, ?) OR dataset_id = ?',
            [$fixture['source_variable_id'], $fixture['destination_variable_id'], $fixture['dataset_id']],
        );
        if ($existingRows !== 0 || $existingVariables !== 0 || in_array($fixture['table_name'], $this->tableNames($pdo), true)) {
            throw new RuntimeException('The deterministic fixture namespace is not clean; refusing to delete pre-existing state.');
        }
    }

    /** @param array{dataset_id: string, source_variable_id: string, destination_variable_id: string, table_name: string, dataset_name: string} $fixture */
    private function purgeDirtyNamespaceFixture(PDO $pdo, Connection $connection, array $fixture): void
    {
        if ($connection->profileName === 'dolt') {
            return;
        }

        (new NormativeCatalog($pdo))->createTables();
        $datasetName = $this->scalar($pdo, 'SELECT dataset_name FROM dataset WHERE dataset_id = ?', [$fixture['dataset_id']]);
        self::assertSame('preexisting deterministic namespace', $datasetName);
        $pdo->prepare('DELETE FROM dataset WHERE dataset_id = ?')->execute([$fixture['dataset_id']]);
        $pdo->exec('DROP TABLE IF EXISTS ' . $this->qualifiedTable($connection, $fixture['table_name']));
    }

    private function existingTargetPlan(): TransformationPlan
    {
        return new TransformationPlan($this->activeFixtureId(), [
            new RecodeOperation('SourceValue', 'Destination', [
                new RecodeRule(
                    new ExactValueSelector(ScalarValue::number(1)),
                    new AssignValueAction(ScalarValue::number(10)),
                ),
                new RecodeRule(
                    new NumericRangeSelector(2.0, 3.0),
                    new AssignValueAction(ScalarValue::number(20)),
                ),
                new RecodeRule(
                    new MissingValueSelector(),
                    new AssignValueAction(ScalarValue::number(99)),
                ),
                new RecodeRule(new ElseSelector(), new CopySourceAction()),
            ]),
            new SetVariableLabelOperation('Destination', 'Recoded destination'),
            new SetValueLabelsOperation('Destination', [
                new ValueLabel(ScalarValue::number(10), 'Ten'),
                new ValueLabel(ScalarValue::number(20), 'Twenty'),
                new ValueLabel(ScalarValue::number(99), 'Missing source'),
            ]),
        ]);
    }

    private function createTargetPlan(): TransformationPlan
    {
        return new TransformationPlan($this->activeFixtureId(), [
            new RecodeOperation('SourceValue', 'CreatedTarget', [
                new RecodeRule(
                    new ExactValueSelector(ScalarValue::number(1)),
                    new AssignValueAction(ScalarValue::number(10)),
                ),
                new RecodeRule(
                    new NumericRangeSelector(2.0, 3.0),
                    new AssignValueAction(ScalarValue::number(20)),
                ),
                new RecodeRule(
                    new MissingValueSelector(),
                    new AssignValueAction(ScalarValue::number(99)),
                ),
                new RecodeRule(new ElseSelector(), new CopySourceAction()),
            ]),
            new SetVariableLabelOperation('CreatedTarget', 'Recoded created target'),
            new SetValueLabelsOperation('CreatedTarget', [
                new ValueLabel(ScalarValue::number(10), 'Ten'),
                new ValueLabel(ScalarValue::number(20), 'Twenty'),
                new ValueLabel(ScalarValue::number(99), 'Missing source'),
            ]),
        ]);
    }

    private function servicePdo(string $expectedProfile, ?string $environmentPrefix, string $driver): PDO
    {
        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO ' . $driver . ' is not available in this PHP environment.');
        }

        if ($expectedProfile === 'sqlite') {
            $pdo = new PDO('sqlite::memory:', options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');

            return $pdo;
        }

        if ($environmentPrefix === null) {
            throw new RuntimeException('A non-SQLite integration profile requires an environment prefix.');
        }

        $dsn = getenv($environmentPrefix . '_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped($environmentPrefix . '_DSN is not configured.');
        }

        $user = getenv($environmentPrefix . '_USER');
        $password = getenv($environmentPrefix . '_PASSWORD');

        return new PDO(
            $dsn,
            is_string($user) ? $user : null,
            is_string($password) ? $password : null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function datasetRow(PDO $pdo): array
    {
        $rows = $this->rows(
            $pdo,
            'SELECT dataset_id, spec_version, source_format, physical_table_schema, physical_table_name, dataset_name, source_case_count, imported_at '
            . 'FROM dataset WHERE dataset_id = ?',
            [$this->activeFixtureId()],
        );
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /** @return list<array<string, mixed>> */
    private function variableIdentityRows(PDO $pdo): array
    {
        return $this->rows(
            $pdo,
            'SELECT variable_id, source_ordinal, source_name, physical_name, storage_kind, declared_string_width '
            . 'FROM variable WHERE dataset_id = ? ORDER BY source_ordinal',
            [$this->activeFixtureId()],
        );
    }

    /** @return list<array{ordinal: int, code_kind: string, value: float, label: string}> */
    private function destinationValueLabels(PDO $pdo): array
    {
        return $this->valueLabelsForVariable($pdo, 'Destination');
    }

    /** @return list<array{ordinal: int, code_kind: string, value: float, label: string}> */
    private function valueLabelsForVariable(PDO $pdo, string $sourceName): array
    {
        $rows = $this->rows(
            $pdo,
            'SELECT label.ordinal, label.code_kind, label.numeric_code, label.label '
            . 'FROM variable '
            . 'JOIN variable_value_label_set link ON link.variable_id = variable.variable_id '
            . 'JOIN value_label label ON label.value_label_set_id = link.value_label_set_id '
            . 'WHERE variable.dataset_id = ? AND variable.source_name = ? '
            . 'ORDER BY label.ordinal',
            [$this->activeFixtureId(), $sourceName],
        );

        return array_map(
            static function (array $row): array {
                return [
                    'ordinal' => (int) $row['ordinal'],
                    'code_kind' => (string) $row['code_kind'],
                    'value' => (float) $row['numeric_code'],
                    'label' => (string) $row['label'],
                ];
            },
            $rows,
        );
    }

    /** @return list<array<string, mixed>> */
    private function valueLabelSetRows(PDO $pdo): array
    {
        return $this->rows(
            $pdo,
            'SELECT value_label_set_id, dataset_id, name FROM value_label_set WHERE dataset_id = ? ORDER BY value_label_set_id',
            [$this->activeFixtureId()],
        );
    }

    /** @return list<array<string, mixed>> */
    private function valueLabelRows(PDO $pdo): array
    {
        return $this->rows(
            $pdo,
            'SELECT value_label_id, value_label_set_id, ordinal, code_kind, numeric_code, string_code, label '
            . 'FROM value_label WHERE value_label_set_id IN (SELECT value_label_set_id FROM value_label_set WHERE dataset_id = ?) '
            . 'ORDER BY value_label_set_id, ordinal, value_label_id',
            [$this->activeFixtureId()],
        );
    }

    /** @return list<array<string, mixed>> */
    private function variableValueLabelSetRows(PDO $pdo): array
    {
        return $this->rows(
            $pdo,
            'SELECT variable_id, value_label_set_id FROM variable_value_label_set '
            . 'WHERE variable_id IN (SELECT variable_id FROM variable WHERE dataset_id = ?) '
            . 'ORDER BY variable_id, value_label_set_id',
            [$this->activeFixtureId()],
        );
    }

    /** @return list<float|null> */
    private function numericColumn(
        PDO $pdo,
        Connection $connection,
        string $tableName,
        string $column,
    ): array {
        $statement = $pdo->query(
            'SELECT ' . $connection->profile->quoteIdentifier($column)
            . ' FROM ' . $this->qualifiedTable($connection, $tableName)
            . ' ORDER BY ' . $connection->profile->quoteIdentifier('__case_ordinal'),
        );
        self::assertInstanceOf(PDOStatement::class, $statement);

        return array_values(array_map(
            static fn(mixed $value): ?float => $value === null ? null : (float) $value,
            $statement->fetchAll(PDO::FETCH_COLUMN),
        ));
    }

    /**
     * @param list<string> $columns
     * @return list<array<string, int|float|null>>
     */
    private function tableRows(PDO $pdo, Connection $connection, string $tableName, array $columns): array
    {
        $statement = $pdo->query(
            'SELECT ' . implode(', ', array_map($connection->profile->quoteIdentifier(...), $columns))
            . ' FROM ' . $this->qualifiedTable($connection, $tableName)
            . ' ORDER BY ' . $connection->profile->quoteIdentifier('__case_ordinal'),
        );
        self::assertInstanceOf(PDOStatement::class, $statement);

        return array_values(array_map(
            static function (array $row) use ($columns): array {
                $normalized = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    $normalized[$column] = $column === '__case_ordinal'
                        ? (int) $value
                        : ($value === null ? null : (float) $value);
                }

                return $normalized;
            },
            $statement->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return list<string> */
    private function tableNames(PDO $pdo): array
    {
        $sql = match ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            'pgsql' => "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = current_schema() ORDER BY tablename",
            'mysql' => 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name',
            default => throw new RuntimeException('Unsupported integration driver.'),
        };

        $statement = $pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    private function tableColumns(PDO $pdo, Connection $connection, string $tableName): array
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $rows = match ($driver) {
            'sqlite' => $this->query($pdo, 'PRAGMA table_info(' . $connection->profile->quoteIdentifier($tableName) . ')')->fetchAll(PDO::FETCH_ASSOC),
            'pgsql' => $this->rows(
                $pdo,
                'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position',
                [$tableName],
            ),
            'mysql' => $this->rows(
                $pdo,
                'SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position',
                [$tableName],
            ),
            default => throw new RuntimeException('Unsupported integration driver.'),
        };

        if ($driver === 'sqlite') {
            return array_values(array_map(
                static fn(array $column): string => (string) $column['name'],
                $rows,
            ));
        }

        return array_values(array_map(
            static fn(array $column): string => (string) $column['column_name'],
            $rows,
        ));
    }

    /** @param list<string> $tables */
    private function assertNoArtifactTables(array $tables): void
    {
        $artifactTables = array_values(array_filter(
            $tables,
            static fn(string $table): bool => preg_match('/snapshot|staging|copied|derived|rollback|parallel|history/i', $table) === 1,
        ));

        self::assertSame([], $artifactTables);
    }

    /** @return array{dataset_id: string, source_variable_id: string, destination_variable_id: string, table_name: string, dataset_name: string} */
    private function reserveFixtureIdentity(Connection $connection): array
    {
        $sequence = ++$this->fixtureSequence;
        if ($this->name() === 'testExistingTargetTransformationPreservesPhysicalIdentityInPlace' && $sequence === 1) {
            return [
                'dataset_id' => self::DATASET_ID,
                'source_variable_id' => self::SOURCE_VARIABLE_ID,
                'destination_variable_id' => self::DESTINATION_VARIABLE_ID,
                'table_name' => 'inplace_existing_target_' . $connection->profileName,
                'dataset_name' => 'in-place existing-target ' . $connection->profileName,
            ];
        }

        $scope = $this->name() . ':' . $this->dataName() . ':' . $connection->profileName . ':' . $sequence;
        $suffix = substr(hash('sha256', $scope), 0, 12);

        return [
            'dataset_id' => $this->fixtureUuid($scope . ':dataset'),
            'source_variable_id' => $this->fixtureUuid($scope . ':source'),
            'destination_variable_id' => $this->fixtureUuid($scope . ':destination'),
            'table_name' => 'inplace_' . $connection->profileName . '_' . $suffix,
            'dataset_name' => 'in-place ' . $connection->profileName . ' ' . $suffix,
        ];
    }

    private function fixtureUuid(string $scope): string
    {
        $hash = hash('sha256', $scope);

        return substr($hash, 0, 8) . '-'
            . substr($hash, 8, 4) . '-5'
            . substr($hash, 12, 3) . '-a'
            . substr($hash, 15, 3) . '-'
            . substr($hash, 18, 12);
    }

    /** @return array{dataset_id: string, source_variable_id: string, destination_variable_id: string, table_name: string, dataset_name: string} */
    private function activeFixture(): array
    {
        if ($this->activeFixture === null) {
            throw new RuntimeException('A fixture must be installed before querying its catalog state.');
        }

        return $this->activeFixture;
    }

    private function activeFixtureId(): string
    {
        return $this->activeFixture()['dataset_id'];
    }

    /** @param array{dataset_id: string, source_variable_id: string, destination_variable_id: string, table_name: string, dataset_name: string} $fixture */
    private function commitDoltFixture(PDO $pdo, Connection $connection, array $fixture): void
    {
        if ($connection->profileName !== 'dolt') {
            return;
        }

        $statement = $pdo->prepare('CALL DOLT_COMMIT(?, ?)');
        $statement->execute(['-Am', 'OpenStatSpec in-place fixture ' . $fixture['dataset_id']]);
    }

    private function assertDoltWorkingSetCleanBeforeFixture(PDO $pdo, Connection $connection): void
    {
        if ($connection->profileName !== 'dolt') {
            return;
        }

        $statement = $pdo->query('SELECT table_name FROM dolt_status ORDER BY table_name');
        self::assertInstanceOf(PDOStatement::class, $statement);
        $dirtyTables = array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
        if ($dirtyTables !== []) {
            throw new RuntimeException(
                'Dolt fixture setup requires a clean working set before its baseline commit; dirty tables: '
                . implode(', ', $dirtyTables) . '.',
            );
        }
    }

    private function qualifiedTable(Connection $connection, string $tableName): string
    {
        return $connection->profile->quoteIdentifier($tableName);
    }

    private function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return $statement;
    }

    /** @param list<mixed> $parameters */
    private function scalar(PDO $pdo, string $sql, array $parameters): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    /**
     * @param list<mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(PDO $pdo, string $sql, array $parameters): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
