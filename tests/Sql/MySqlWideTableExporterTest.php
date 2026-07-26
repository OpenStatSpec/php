<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Sql;

use OpenStatSpec\Sql\MySqlWideTableExporter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use SPSS\Sav\Alignment;
use SPSS\Sav\Measure;
use SPSS\Sav\MissingValuesKind;

final class MySqlWideTableExporterTest extends TestCase
{
    public function testRestoresCoreDictionaryDisplayAndFileMetadata(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statements = [
            'datasets' => $this->statement([['table_name' => 'dataset_fixture']]),
            'variables' => $this->statement([], [[
                'ordinal' => 1,
                'source_name' => 'Score',
                'column_name' => 'score',
                'storage_kind' => 'numeric',
                'source_width' => 0,
                'format_family' => 5,
                'format_width' => 8,
                'format_decimals' => 0,
                'label' => 'Survey score',
            ]]),
            'cases' => $this->statement([['score' => '1.5']]),
            'labels' => $this->statement([
                ['value_kind' => 'numeric', 'numeric_value' => '1', 'text_value' => null, 'label' => 'Yes'],
                ['value_kind' => 'numeric', 'numeric_value' => '2', 'text_value' => null, 'label' => 'No'],
            ]),
            'missing' => $this->statement([], [], -3),
            'missing_values' => $this->statement([
                ['value_kind' => 'numeric', 'numeric_value' => '-99', 'text_value' => null],
                ['value_kind' => 'numeric', 'numeric_value' => '99', 'text_value' => null],
                ['value_kind' => 'numeric', 'numeric_value' => '-1', 'text_value' => null],
            ]),
            'display' => $this->statement([['measurement_level' => '3', 'display_width' => '12', 'alignment' => '1']]),
            'file_label' => $this->statement([], [], 'Customer source'),
            'documents' => $this->statement([], ['First document line', 'Second document line']),
            'technical' => $this->statement([[
                'source_version' => '31.0',
                'provenance' => 'unit-test',
                'encoding' => 'UTF-8',
                'product_name' => 'SPSS Statistics',
            ]]),
        ];
        $pdo->method('prepare')->willReturnCallback(static function (string $sql) use ($statements): PDOStatement {
            return match (true) {
                str_contains($sql, 'FROM datasets') => $statements['datasets'],
                str_contains($sql, 'SELECT ordinal, source_name') => $statements['variables'],
                str_starts_with($sql, 'SELECT `score`') => $statements['cases'],
                str_contains($sql, 'FROM value_labels') => $statements['labels'],
                str_contains($sql, 'FROM missing_rules') => $statements['missing'],
                str_contains($sql, 'FROM missing_rule_values') => $statements['missing_values'],
                str_contains($sql, 'FROM variable_display_metadata') => $statements['display'],
                str_contains($sql, 'FROM dataset_metadata') => $statements['file_label'],
                str_contains($sql, 'FROM documents') => $statements['documents'],
                str_contains($sql, 'FROM file_technical_metadata') => $statements['technical'],
                default => throw new \LogicException('Unexpected catalogue query: ' . $sql),
            };
        });

        $export = (new MySqlWideTableExporter($pdo))->export('fixture', 'zsav');
        $variable = $export['dataset']->variables()[0];

        self::assertSame(1, $export['caseCount']);
        self::assertSame(['deferred_variable_extensions'], array_map(static fn($diagnostic): string => $diagnostic->code, $export['diagnostics']));
        self::assertSame([1.5], $export['dataset']->rows()[0]);
        self::assertSame(['Score'], $variable->valueLabels->variableNames());
        self::assertSame(['Yes', 'No'], array_map(static fn($label): string => $label->label, $variable->valueLabels->labels()));
        self::assertSame(MissingValuesKind::RANGE_AND_VALUE, $variable->missingValues->kind);
        self::assertSame(-99.0, $variable->missingValues->lower);
        self::assertSame(99.0, $variable->missingValues->upper);
        self::assertSame(-1.0, $variable->missingValues->additionalValue);
        self::assertSame(Measure::SCALE, $variable->measure);
        self::assertSame(Alignment::RIGHT, $variable->alignment);
        self::assertSame(12, $variable->columns);
        self::assertSame('Customer source', $export['dataset']->metadata->label);
        self::assertSame(['First document line', 'Second document line'], $export['dataset']->metadata->documents());
        self::assertSame('zsav', $export['dataset']->technicalMetadata->sourceFormat);
        self::assertSame('$FL3', $export['dataset']->technicalMetadata->recordType);
        self::assertSame('31.0', $export['dataset']->technicalMetadata->sourceVersion);
        self::assertSame('unit-test', $export['dataset']->technicalMetadata->provenance);
        self::assertSame('UTF-8', $export['dataset']->technicalMetadata->encoding);
        self::assertSame('SPSS Statistics', $export['dataset']->technicalMetadata->productName);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<mixed> $all
     */
    private function statement(array $rows = [], array $all = [], mixed $column = false): PDOStatement
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn($all);
        $statement->method('fetchColumn')->willReturn($column);
        $index = 0;
        $statement->method('fetch')->willReturnCallback(static function () use ($rows, &$index): array|false {
            return $rows[$index++] ?? false;
        });

        return $statement;
    }
}
