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
use SPSS\Sav\Variable;
use SPSS\Sav\VariableAttribute;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableFormat;
use SPSS\Sav\VariableMetadata;
use SPSS\Sav\VariableRole;
use SPSS\Sav\VariableSet;
use SPSS\Sav\VariableType;

/**
 * Uses actual SAV/ZSAV files and the real php-spss V3 engine.  The same
 * dictionary matrix is exercised through SQLite and every configured server
 * profile; no fake engine is involved.
 */
final class StrictSpssDictionaryConformanceTest extends TestCase
{
    public function testStrictDictionarySemanticsRoundTripInEveryAvailableProfile(): void
    {
        $engine = new PhpSpssEngine();
        foreach ($this->profiles() as $profile => $pdo) {
            foreach (['sav' => '$FL2', 'zsav' => '$FL3'] as $format => $header) {
                $token = bin2hex(random_bytes(6));
                $datasetName = 'strict dictionary ' . $profile . ' ' . $format . ' ' . $token;
                $source = sys_get_temp_dir() . '/openstatspec-dictionary-source-' . $token . '.' . $format;
                $target = sys_get_temp_dir() . '/openstatspec-dictionary-target-' . $token . '.' . $format;
                $tableName = null;
                $label = 'Strict ' . $profile . ' dictionary fixture';

                try {
                    $fixture = $this->fixture($format, $label);
                    $engine->write($source, $fixture);
                    self::assertSame($header, $this->header($source));

                    $adapter = new SpssAdapter($pdo, $engine);
                    $adapter->import($source, $datasetName);
                    $tableName = $this->tableName($pdo, $datasetName);
                    $this->assertCatalogMatrix($pdo, $datasetName);

                    $result = $adapter->export($datasetName, $target);
                    self::assertSame([], $result->diagnostics);
                    self::assertSame($header, $this->header($target));
                    $roundTrip = $engine->read($target);
                    self::assertEquals($fixture->rows(), $roundTrip->rows());
                    $this->assertDictionaryMatrix($roundTrip, $format, $label);
                } finally {
                    $this->cleanup($pdo, $datasetName, $tableName);
                    @unlink($source);
                    @unlink($target);
                }
            }
        }
    }

    /** @return array<string, PDO> */
    private function profiles(): array
    {
        $profiles = [];
        if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $profiles['sqlite'] = new PDO('sqlite::memory:');
        }
        foreach ([
            'mysql' => 'OPENSTATSPEC_MYSQL',
            'mariadb' => 'OPENSTATSPEC_MARIADB',
            'dolt' => 'OPENSTATSPEC_DOLT',
            'postgresql' => 'OPENSTATSPEC_PG',
        ] as $name => $prefix) {
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

    private function fixture(string $format, string $label): Dataset
    {
        return new Dataset(
            new VariableDictionary([
                new VariableMetadata(
                    name: 'Format_number',
                    type: VariableType::NUMERIC,
                    width: 0,
                    printFormat: new VariableFormat(Variable::FORMAT_TYPE_F, 10, 2),
                    writeFormat: new VariableFormat(Variable::FORMAT_TYPE_COMMA, 12, 2),
                    label: 'Number with distinct print and write formats',
                    valueLabels: new ValueLabelSet([new ValueLabel(1234.5, 'Formatted value')], ['Format_number']),
                    missingValues: MissingValues::none(),
                    measure: Measure::SCALE,
                    alignment: Alignment::RIGHT,
                    columns: 12,
                    role: VariableRole::TARGET,
                    attributes: [new VariableAttribute('Format_number', 'AuditTrail', ['created', 'reviewed', 'approved'])],
                    dictionaryIndex: 1,
                ),
                new VariableMetadata(
                    name: 'Discrete_numeric',
                    type: VariableType::NUMERIC,
                    width: 0,
                    printFormat: new VariableFormat(Variable::FORMAT_TYPE_F, 8),
                    writeFormat: new VariableFormat(Variable::FORMAT_TYPE_F, 8),
                    valueLabels: new ValueLabelSet([new ValueLabel(-1, 'Refused'), new ValueLabel(-2, 'Not asked'), new ValueLabel(-3, 'Unknown')], ['Discrete_numeric']),
                    missingValues: MissingValues::discrete(-1, -2, -3),
                    measure: Measure::NOMINAL,
                    dictionaryIndex: 2,
                ),
                new VariableMetadata(
                    name: 'Range_numeric',
                    type: VariableType::NUMERIC,
                    width: 0,
                    printFormat: new VariableFormat(Variable::FORMAT_TYPE_F, 8),
                    writeFormat: new VariableFormat(Variable::FORMAT_TYPE_F, 8),
                    valueLabels: new ValueLabelSet([new ValueLabel(1, 'Lower missing boundary'), new ValueLabel(3, 'Upper missing boundary')], ['Range_numeric']),
                    missingValues: MissingValues::range(1, 3),
                    measure: Measure::ORDINAL,
                    dictionaryIndex: 3,
                ),
                new VariableMetadata(
                    name: 'Range_and_value',
                    type: VariableType::NUMERIC,
                    width: 0,
                    printFormat: new VariableFormat(Variable::FORMAT_TYPE_F, 8),
                    writeFormat: new VariableFormat(Variable::FORMAT_TYPE_F, 8),
                    valueLabels: new ValueLabelSet([new ValueLabel(99, 'Other missing value')], ['Range_and_value']),
                    missingValues: MissingValues::rangeAndValue(10, 20, 99),
                    measure: Measure::ORDINAL,
                    dictionaryIndex: 4,
                ),
                new VariableMetadata(
                    name: 'Text_missing',
                    type: VariableType::STRING,
                    width: 12,
                    printFormat: new VariableFormat(Variable::FORMAT_TYPE_A, 12),
                    writeFormat: new VariableFormat(Variable::FORMAT_TYPE_A, 12),
                    valueLabels: new ValueLabelSet([new ValueLabel('N/A', 'Not applicable'), new ValueLabel('UNKNOWN', 'Unknown')], ['Text_missing']),
                    missingValues: MissingValues::discrete('N/A', 'UNKNOWN'),
                    measure: Measure::NOMINAL,
                    dictionaryIndex: 5,
                ),
                $this->numeric('Date_value', Variable::FORMAT_TYPE_DATE, 11, 0, 9, 0, 6),
                $this->numeric('Time_value', Variable::FORMAT_TYPE_TIME, 11, 2, 10, 1, 7),
                $this->numeric('Datetime_value', Variable::FORMAT_TYPE_DATETIME, 20, 0, 18, 0, 8),
                $this->numeric('Dtime_value', Variable::FORMAT_TYPE_DTIME, 12, 2, 10, 1, 9),
                $this->numeric('Currency_value', Variable::FORMAT_TYPE_DOLLAR, 12, 2, 10, 2, 10),
                $this->numericFlag('Flag_a', 11),
                $this->numericFlag('Flag_b', 12),
                $this->textFlag('Text_a', 13),
                $this->textFlag('Text_b', 14),
            ]),
            [
                [1234.5, -1, 2, 99, 'N/A', 13885344000.0, 3723.5, 13885347723.5, 183845.75, 99.95, 1, 0, 'Y', 'N'],
                [0.0, -2, 4, 9, 'present', 0.0, 0.0, 0.0, 0.0, -12.5, 0, 1, 'N', 'Y'],
            ],
            new FileMetadata(
                $label,
                weightVariableName: 'Format_number',
                documents: ['First conformance document', 'Second conformance document'],
                attributes: [new FileAttribute('SourceHistory', ['imported', 'validated', 'approved'])],
                variableSets: [new VariableSet('Formatting', ['Format_number', 'Date_value', 'Time_value', 'Datetime_value', 'Dtime_value', 'Currency_value'])],
                multipleResponseSets: [
                    new MultipleResponseSet('$MC', MultipleResponseSetType::CATEGORY, ['Date_value', 'Time_value'], 'Category multiple-response set'),
                    new MultipleResponseSet('$MDN', MultipleResponseSetType::DICHOTOMY, ['Flag_a', 'Flag_b'], 'Numeric dichotomy multiple-response set', 1, MultipleResponseCategoryLabels::COUNTED_VALUES, MultipleResponseLabelSource::VARIABLE_LABEL),
                    new MultipleResponseSet('$MDT', MultipleResponseSetType::DICHOTOMY, ['Text_a', 'Text_b'], 'Text dichotomy multiple-response set', 'Y', MultipleResponseCategoryLabels::COUNTED_VALUES, MultipleResponseLabelSource::SET_LABEL),
                ],
            ),
            new FileTechnicalMetadata(sourceFormat: $format, compression: $format === 'zsav' ? 2 : 1),
        );
    }

    private function assertCatalogMatrix(PDO $pdo, string $datasetName): void
    {
        self::assertSame(14, $this->catalogCount($pdo, 'variables', $datasetName));
        self::assertSame(3, $this->catalogCount($pdo, 'file_attributes', $datasetName));
        self::assertSame(3, $this->catalogCount($pdo, 'variable_attributes', $datasetName));
        self::assertSame(3, $this->catalogCount($pdo, 'multiple_response_sets', $datasetName));
        self::assertSame(4, $this->catalogCount($pdo, 'missing_rules', $datasetName));
        self::assertSame(10, $this->catalogCount($pdo, 'missing_rule_values', $datasetName));
        self::assertSame(15, $this->catalogCount($pdo, 'value_labels', $datasetName));
        $statement = $pdo->prepare('SELECT format_family, format_width, format_decimals, write_format_family, write_format_width, write_format_decimals FROM variables WHERE dataset_name = ? AND ordinal = 1');
        $statement->execute([$datasetName]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertSame([Variable::FORMAT_TYPE_F, 10, 2, Variable::FORMAT_TYPE_COMMA, 12, 2], array_map(static fn(mixed $value): int => (int) $value, array_values(is_array($row) ? $row : [])));
    }

    private function assertDictionaryMatrix(Dataset $dataset, string $format, string $label): void
    {
        self::assertSame($format, $dataset->technicalMetadata->sourceFormat);
        self::assertSame($format === 'zsav' ? 2 : 1, $dataset->technicalMetadata->compression);
        self::assertSame($label, $dataset->metadata->label);
        self::assertSame('Format_number', $dataset->metadata->weightVariableName);
        self::assertSame(['First conformance document', 'Second conformance document'], $dataset->metadata->documents());
        self::assertEquals([new FileAttribute('SourceHistory', ['imported', 'validated', 'approved'])], $dataset->metadata->attributes());
        self::assertEquals([new VariableSet('Formatting', ['Format_number', 'Date_value', 'Time_value', 'Datetime_value', 'Dtime_value', 'Currency_value'])], $dataset->metadata->variableSets());

        $variables = $dataset->variables();
        self::assertCount(14, $variables);
        self::assertEquals(new VariableFormat(Variable::FORMAT_TYPE_F, 10, 2), $variables[0]->printFormat);
        self::assertEquals(new VariableFormat(Variable::FORMAT_TYPE_COMMA, 12, 2), $variables[0]->writeFormat);
        self::assertEquals([new VariableAttribute('Format_number', 'AuditTrail', ['created', 'reviewed', 'approved'])], $variables[0]->attributes());
        self::assertEquals([new ValueLabel(1234.5, 'Formatted value')], $variables[0]->valueLabels->labels());
        self::assertEquals(MissingValues::none(), $variables[0]->missingValues);
        self::assertEquals(MissingValues::discrete(-1, -2, -3), $variables[1]->missingValues);
        self::assertEquals(MissingValues::range(1, 3), $variables[2]->missingValues);
        self::assertEquals(MissingValues::rangeAndValue(10, 20, 99), $variables[3]->missingValues);
        self::assertEquals(MissingValues::discrete('N/A', 'UNKNOWN'), $variables[4]->missingValues);
        self::assertFormats($variables[5], Variable::FORMAT_TYPE_DATE, 11, 0, 9, 0);
        self::assertFormats($variables[6], Variable::FORMAT_TYPE_TIME, 11, 2, 10, 1);
        self::assertFormats($variables[7], Variable::FORMAT_TYPE_DATETIME, 20, 0, 18, 0);
        self::assertFormats($variables[8], Variable::FORMAT_TYPE_DTIME, 12, 2, 10, 1);
        self::assertFormats($variables[9], Variable::FORMAT_TYPE_DOLLAR, 12, 2, 10, 2);
        self::assertEquals([
            new MultipleResponseSet('$MC', MultipleResponseSetType::CATEGORY, ['Date_value', 'Time_value'], 'Category multiple-response set'),
            new MultipleResponseSet('$MDN', MultipleResponseSetType::DICHOTOMY, ['Flag_a', 'Flag_b'], 'Numeric dichotomy multiple-response set', 1, MultipleResponseCategoryLabels::COUNTED_VALUES, MultipleResponseLabelSource::VARIABLE_LABEL),
            new MultipleResponseSet('$MDT', MultipleResponseSetType::DICHOTOMY, ['Text_a', 'Text_b'], 'Text dichotomy multiple-response set', 'Y', MultipleResponseCategoryLabels::COUNTED_VALUES, MultipleResponseLabelSource::SET_LABEL),
        ], $dataset->metadata->multipleResponseSets());
    }

    private function numeric(string $name, int $code, int $printWidth, int $printDecimals, int $writeWidth, int $writeDecimals, int $index): VariableMetadata
    {
        return new VariableMetadata(name: $name, type: VariableType::NUMERIC, width: 0, printFormat: new VariableFormat($code, $printWidth, $printDecimals), writeFormat: new VariableFormat($code, $writeWidth, $writeDecimals), label: 'SPSS format value stored as numeric', dictionaryIndex: $index);
    }

    private function numericFlag(string $name, int $index): VariableMetadata
    {
        return new VariableMetadata(name: $name, type: VariableType::NUMERIC, width: 0, printFormat: new VariableFormat(Variable::FORMAT_TYPE_F, 1), writeFormat: new VariableFormat(Variable::FORMAT_TYPE_F, 1), valueLabels: new ValueLabelSet([new ValueLabel(0, 'No'), new ValueLabel(1, 'Yes')], [$name]), dictionaryIndex: $index);
    }

    private function textFlag(string $name, int $index): VariableMetadata
    {
        return new VariableMetadata(name: $name, type: VariableType::STRING, width: 1, printFormat: new VariableFormat(Variable::FORMAT_TYPE_A, 1), writeFormat: new VariableFormat(Variable::FORMAT_TYPE_A, 1), valueLabels: new ValueLabelSet([new ValueLabel('Y', 'Selected')], [$name]), dictionaryIndex: $index);
    }

    private function assertFormats(VariableMetadata $variable, int $code, int $printWidth, int $printDecimals, int $writeWidth, int $writeDecimals): void
    {
        self::assertSame(VariableType::NUMERIC, $variable->type);
        self::assertEquals(new VariableFormat($code, $printWidth, $printDecimals), $variable->printFormat);
        self::assertEquals(new VariableFormat($code, $writeWidth, $writeDecimals), $variable->writeFormat);
    }

    private function tableName(PDO $pdo, string $datasetName): string
    {
        $statement = $pdo->prepare('SELECT table_name FROM datasets WHERE dataset_name = ?');
        $statement->execute([$datasetName]);
        $tableName = $statement->fetchColumn();
        if (!is_string($tableName) || $tableName === '') {
            throw new RuntimeException('The dataset table was not catalogued.');
        }
        return $tableName;
    }

    private function catalogCount(PDO $pdo, string $table, string $datasetName): int
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE dataset_name = ?');
        $statement->execute([$datasetName]);
        return (int) $statement->fetchColumn();
    }

    private function cleanup(PDO $pdo, string $datasetName, ?string $tableName): void
    {
        if ($tableName !== null) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($pdo, $tableName));
        }
        foreach (['multiple_response_set_members', 'multiple_response_sets', 'variable_set_members', 'variable_sets', 'variable_attributes', 'file_attributes', 'variable_roles', 'variable_display_metadata', 'missing_rule_values', 'missing_rules', 'value_labels', 'documents', 'file_technical_metadata', 'dataset_metadata', 'dataset_weight_variables', 'variables', 'datasets'] as $table) {
            try {
                $statement = $pdo->prepare('DELETE FROM ' . $table . ' WHERE dataset_name = ?');
                $statement->execute([$datasetName]);
            } catch (PDOException) {
                // A profile can omit an empty optional catalogue table on an early failure.
            }
        }
    }

    private function quote(PDO $pdo, string $identifier): string
    {
        return match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'mysql' => '`' . str_replace('`', '``', $identifier) . '`',
            default => '"' . str_replace('"', '""', $identifier) . '"',
        };
    }

    private function header(string $path): string
    {
        $header = file_get_contents($path, false, null, 0, 4);
        if (!is_string($header)) {
            throw new RuntimeException('Could not read the SPSS file header.');
        }
        return $header;
    }
}
