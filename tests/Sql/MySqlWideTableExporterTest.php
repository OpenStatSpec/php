<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Sql;

use OpenStatSpec\Sql\MySqlWideTableExporter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use SPSS\Sav\Alignment;
use SPSS\Sav\FileAttribute;
use SPSS\Sav\MultipleResponseCategoryLabels;
use SPSS\Sav\MultipleResponseLabelSource;
use SPSS\Sav\MultipleResponseSetType;
use SPSS\Sav\Measure;
use SPSS\Sav\MissingValuesKind;
use SPSS\Sav\VariableAttribute;
use SPSS\Sav\VariableRole;

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
            'roles' => $this->statement([], [], 0),
            'variable_attributes' => $this->statement(),
            'file_attributes' => $this->statement(),
            'variable_sets' => $this->statement(),
            'variable_set_members' => $this->statement(),
            'multiple_response_sets' => $this->statement(),
            'multiple_response_set_members' => $this->statement(),
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
                str_contains($sql, 'FROM variable_roles') => $statements['roles'],
                str_contains($sql, 'FROM variable_attributes') => $statements['variable_attributes'],
                str_contains($sql, 'FROM file_attributes') => $statements['file_attributes'],
                str_contains($sql, 'FROM variable_sets') => $statements['variable_sets'],
                str_contains($sql, 'FROM variable_set_members') => $statements['variable_set_members'],
                str_contains($sql, 'FROM multiple_response_sets') => $statements['multiple_response_sets'],
                str_contains($sql, 'FROM multiple_response_set_members') => $statements['multiple_response_set_members'],
                str_contains($sql, 'FROM dataset_metadata') => $statements['file_label'],
                str_contains($sql, 'FROM documents') => $statements['documents'],
                str_contains($sql, 'FROM file_technical_metadata') => $statements['technical'],
                default => throw new \LogicException('Unexpected catalogue query: ' . $sql),
            };
        });

        $export = (new MySqlWideTableExporter($pdo))->export('fixture', 'zsav');
        $variable = $export['dataset']->variables()[0];

        self::assertSame(1, $export['caseCount']);
        self::assertSame([], $export['diagnostics']);
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


    public function testRestoresVariableRolesAndOrderedAttributes(): void
    {
        $export = $this->extensionExport([
            'variables' => [$this->variable('Score')],
            'roles' => 1,
            'variable_attributes' => [
                ['attribute_name' => 'Origin', 'ordinal' => 1, 'value' => 'CRM'],
                ['attribute_name' => 'Origin', 'ordinal' => 2, 'value' => 'verified'],
            ],
            'file_attributes' => [
                ['attribute_name' => 'Source', 'ordinal' => 1, 'value' => 'production'],
                ['attribute_name' => 'Source', 'ordinal' => 2, 'value' => '2026'],
            ],
        ]);

        self::assertSame([], $export['diagnostics']);
        self::assertSame(VariableRole::TARGET, $export['dataset']->variables()[0]->role);
        self::assertEquals([new VariableAttribute('Score', 'Origin', ['CRM', 'verified'])], $export['dataset']->variables()[0]->attributes());
        self::assertEquals([new FileAttribute('Source', ['production', '2026'])], $export['dataset']->metadata->attributes());
    }

    public function testRestoresOrderedVariableAndMultipleResponseSetMembership(): void
    {
        $export = $this->extensionExport([
            'variables' => [$this->variable('First'), $this->variable('Second', 2)],
            'variable_sets' => [['set_ordinal' => 1, 'name' => 'Core']],
            'variable_set_members' => [
                ['member_ordinal' => 1, 'source_name' => 'Second'],
                ['member_ordinal' => 2, 'source_name' => 'First'],
            ],
            'multiple_response_sets' => [[
                'set_ordinal' => 1,
                'name' => '$Choice',
                'set_type' => 'dichotomy',
                'label' => 'Selected choices',
                'counted_value_kind' => 'numeric',
                'counted_numeric_value' => '1',
                'counted_text_value' => null,
                'category_labels' => 'counted_values',
                'label_source' => 'variable_label',
            ]],
            'multiple_response_set_members' => [
                ['member_ordinal' => 1, 'source_name' => 'Second'],
                ['member_ordinal' => 2, 'source_name' => 'First'],
            ],
        ]);

        self::assertSame(['Second', 'First'], $export['dataset']->metadata->variableSets()[0]->variableNames());
        $multiple = $export['dataset']->metadata->multipleResponseSets()[0];
        self::assertSame('$Choice', $multiple->name);
        self::assertSame(MultipleResponseSetType::DICHOTOMY, $multiple->type);
        self::assertSame(['Second', 'First'], $multiple->variableNames());
        self::assertSame('Selected choices', $multiple->label);
        self::assertSame(1, $multiple->countedValue);
        self::assertSame(MultipleResponseCategoryLabels::COUNTED_VALUES, $multiple->categoryLabels);
        self::assertSame(MultipleResponseLabelSource::VARIABLE_LABEL, $multiple->labelSource);
    }

    /**
     * @param array<string, mixed> $catalog
     * @return array{dataset: \SPSS\Sav\Dataset, caseCount: int, diagnostics: list<\OpenStatSpec\Core\FidelityDiagnostic>}
     */
    private function extensionExport(array $catalog): array
    {
        $catalog += [
            'variables' => [],
            'roles' => 0,
            'variable_attributes' => [],
            'file_attributes' => [],
            'variable_sets' => [],
            'variable_set_members' => [],
            'multiple_response_sets' => [],
            'multiple_response_set_members' => [],
        ];

        $pdo = $this->createMock(PDO::class);
        $statements = [
            'datasets' => $this->statement([['table_name' => 'dataset_fixture']]),
            'variables' => $this->statement([], $catalog['variables']),
            'cases' => $this->statement(),
            'labels' => $this->statement(),
            'missing' => $this->statement([], [], false),
            'display' => $this->statement(),
            'roles' => $this->statement([], [], $catalog['roles']),
            'variable_attributes' => $this->statement($catalog['variable_attributes']),
            'dataset_metadata' => $this->statement([], [], false),
            'documents' => $this->statement([], []),
            'file_attributes' => $this->statement($catalog['file_attributes']),
            'variable_sets' => $this->statement($catalog['variable_sets']),
            'variable_set_members' => $this->statement($catalog['variable_set_members']),
            'multiple_response_sets' => $this->statement($catalog['multiple_response_sets']),
            'multiple_response_set_members' => $this->statement($catalog['multiple_response_set_members']),
            'technical' => $this->statement(),
        ];
        $pdo->method('prepare')->willReturnCallback(static function (string $sql) use ($statements): PDOStatement {
            return match (true) {
                str_contains($sql, 'FROM datasets') => $statements['datasets'],
                str_contains($sql, 'SELECT ordinal, source_name') => $statements['variables'],
                str_contains($sql, 'dataset_fixture') => $statements['cases'],
                str_contains($sql, 'FROM value_labels') => $statements['labels'],
                str_contains($sql, 'FROM missing_rules') => $statements['missing'],
                str_contains($sql, 'FROM variable_display_metadata') => $statements['display'],
                str_contains($sql, 'FROM variable_roles') => $statements['roles'],
                str_contains($sql, 'FROM variable_attributes') => $statements['variable_attributes'],
                str_contains($sql, 'FROM dataset_metadata') => $statements['dataset_metadata'],
                str_contains($sql, 'FROM documents') => $statements['documents'],
                str_contains($sql, 'FROM file_attributes') => $statements['file_attributes'],
                str_contains($sql, 'FROM variable_sets') => $statements['variable_sets'],
                str_contains($sql, 'FROM variable_set_members') => $statements['variable_set_members'],
                str_contains($sql, 'FROM multiple_response_sets') => $statements['multiple_response_sets'],
                str_contains($sql, 'FROM multiple_response_set_members') => $statements['multiple_response_set_members'],
                str_contains($sql, 'FROM file_technical_metadata') => $statements['technical'],
                default => throw new \LogicException('Unexpected catalogue query: ' . $sql),
            };
        });

        return (new MySqlWideTableExporter($pdo))->export('fixture');
    }

    /** @return array<string, int|string|null> */
    private function variable(string $name, int $ordinal = 1): array
    {
        return [
            'ordinal' => $ordinal,
            'source_name' => $name,
            'column_name' => strtolower($name),
            'storage_kind' => 'numeric',
            'source_width' => 0,
            'format_family' => 5,
            'format_width' => 8,
            'format_decimals' => 0,
            'label' => null,
        ];
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
