<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

use OpenStatSpec\Spss\PhpSpssEngine;
use OpenStatSpec\Spss\SpssAdapter;
use PDO;
use PDOException;
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
    public function testRealEngineRoundTripsSavAndZsavThroughPostgreSql(): void
    {
        $pdo = $this->postgres();
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
                self::assertSame(
                    [
                        ['ordinal' => '1', 'source_name' => 'Score', 'storage_kind' => 'numeric'],
                        ['ordinal' => '2', 'source_name' => 'Reason', 'storage_kind' => 'string'],
                        ['ordinal' => '3', 'source_name' => 'LongText', 'storage_kind' => 'string'],
                    ],
                    $this->rows($pdo, 'SELECT ordinal, source_name, storage_kind FROM variables WHERE dataset_name = ? ORDER BY ordinal', [$datasetName]),
                );
                self::assertSame(2, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM documents WHERE dataset_name = ?', [$datasetName]));
                self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM value_labels WHERE dataset_name = ?', [$datasetName]));
                self::assertSame(3, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM missing_rule_values WHERE dataset_name = ?', [$datasetName]));
                self::assertSame(3, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM variable_roles WHERE dataset_name = ?', [$datasetName]));
                self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM file_attributes WHERE dataset_name = ?', [$datasetName]));
                self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM variable_sets WHERE dataset_name = ?', [$datasetName]));
                self::assertSame(1, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM multiple_response_sets WHERE dataset_name = ?', [$datasetName]));

                $result = $adapter->export($datasetName, $targetPath);
                self::assertSame([], $result->diagnostics);
                self::assertSame(2, $result->caseCount);
                self::assertSame($header, $this->fileHeader($targetPath));

                $roundTrip = $engine->read($targetPath);
                self::assertSame($fixture->rows(), $roundTrip->rows());
                self::assertSame($format, $roundTrip->technicalMetadata->sourceFormat);
                self::assertSame($compression, $roundTrip->technicalMetadata->compression);
                self::assertSame('PostgreSQL integration fixture', $roundTrip->metadata->label);
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
            } finally {
                $this->cleanup($pdo, $datasetName, $tableName);
                @unlink($sourcePath);
                @unlink($targetPath);
            }
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

        return new PDO(
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
