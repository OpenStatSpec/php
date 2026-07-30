<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\Binary64;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;
use PDOStatement;
use Throwable;

/**
 * MySQL-family importer for the strict one-source-dataset/one-wide-table
 * contract. MySQL DDL commits implicitly, so a failure after DDL is handled
 * with best-effort compensating cleanup rather than a false atomicity claim.
 */
final readonly class MySqlWideTableImporter
{
    private MySqlProfile $profile;

    public function __construct(private PDO $pdo, ?MySqlProfile $profile = null)
    {
        $this->profile = $profile ?? new MySqlProfile();
    }

    /**
     * @param array<string, mixed> $source
     */
    public function import(
        array $source,
        string $datasetName,
        string $sourcePath = "",
        ?string $verifiedSourceSha256 = null,
    ): MySqlWideTableDefinition {
        $verifiedSourceSha256 = NormativeCatalog::validateSourceSha256($verifiedSourceSha256);
        $variables = $source['variables'] ?? null;
        $rows = $source['data'] ?? null;
        if (!is_array($variables) || !array_is_list($variables) || $variables === []) {
            throw new UnsupportedOperation(
                DiagnosticCode::InvalidSourceDataset,
                'The source dataset must contain an ordered variable list.',
            );
        }
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new UnsupportedOperation(
                DiagnosticCode::InvalidSourceDataset,
                'The source dataset must contain an ordered case list.',
            );
        }

        $this->profile->assertDataset($variables, $rows, $this->pdo);

        $schema = new MySqlSchema($this->pdo, $this->profile);
        // Complete source, physical-name, and width preflight happens before any DDL.
        $definition = $schema->wideTableDefinition($datasetName, $variables);
        $v3Metadata = $this->assertSourceMetadata($source, $variables, $definition);

        $schema->createCatalog();
        $this->pdo->exec($definition->createSql);

        $ownedDefinition = $definition;
        try {
            $this->pdo->beginTransaction();
            $this->storeDatasetMetadata($datasetName, $source);
            $this->storeTechnicalMetadata($datasetName, $source);
            $this->storeCatalogue($datasetName, $variables, $definition);
            $this->storeWeightVariable($datasetName, $source['weightVariableName'] ?? null, $definition);
            $this->storeDisplayMetadata($datasetName, $source['displayParameters'] ?? []);
            $this->storeDictionaryMetadata($datasetName, $variables, $source['valueLabels'] ?? []);
            if ($v3Metadata !== null) {
                (new SqliteV3MetadataImporter($this->pdo))->storeValidated($datasetName, $v3Metadata);
            }
            $this->insertCases($definition, $rows);
            if ($sourcePath !== "" || $verifiedSourceSha256 !== null) {
                $datasetId = (new NormativeCatalog($this->pdo))->storeImportedDataset(
                    $datasetName,
                    $sourcePath,
                    $source,
                    $verifiedSourceSha256,
                );
                $ownedDefinition = new MySqlWideTableDefinition(
                    $definition->tableName,
                    $definition->createSql,
                    $definition->columns,
                    $datasetId,
                );
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // Catalogue DML was rolled back. Only the physical DDL survives.
            $this->dropPhysicalTable($definition);
            throw $exception;
        }

        return $ownedDefinition;
    }

    /**
     * @param array<string, mixed>       $source
     * @param list<array<string, mixed>> $variables
     * @return V3MetadataPlan|null
     */
    private function assertSourceMetadata(
        array $source,
        array $variables,
        MySqlWideTableDefinition $definition,
    ): ?V3MetadataPlan {
        $weightVariableName = $source['weightVariableName'] ?? null;
        if ($weightVariableName !== null) {
            if (!is_string($weightVariableName) || $weightVariableName === '') {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The SPSS weight-variable reference must be a non-empty source variable name.');
            }
            if (!in_array($weightVariableName, array_column($definition->columns, 'sourceName'), true)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The SPSS weight-variable reference must name a source variable.');
            }
        }

        $technical = $source['technicalMetadata'] ?? null;
        if ($technical !== null
            && (!is_array($technical)
                || !is_string($technical['sourceFormat'] ?? null)
                || $technical['sourceFormat'] === ''
                || !is_string($technical['encoding'] ?? null)
                || $technical['encoding'] === '')
        ) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'V3 technical metadata requires a non-empty source format and encoding.');
        }

        foreach ($variables as $variable) {
            $this->writeFormatField($variable, 'writeFormatFamily', 5);
            $this->writeFormatField($variable, 'writeFormatWidth', 8);
            $this->writeFormatField($variable, 'writeFormatDecimals', 0);
            $format = $variable['missingFormat'] ?? 0;
            if (!is_int($format)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SPSS missing-value format must be an integer.');
            }
            if ($format === 0) {
                continue;
            }
            $values = $variable['missingValues'] ?? [];
            if (!is_array($values) || !array_is_list($values)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SPSS user-missing values must be an ordered list.');
            }
            foreach ($values as $value) {
                $this->dictionaryValue($value);
            }
        }

        $records = $source['valueLabels'] ?? [];
        if (!is_array($records) || !array_is_list($records)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SPSS value-label records must be an ordered list.');
        }
        foreach ($records as $record) {
            if (!is_array($record)
                || !is_array($record['indexes'] ?? null)
                || !array_is_list($record['indexes'])
                || !is_array($record['labels'] ?? null)
                || !array_is_list($record['labels'])
            ) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SPSS value-label records must contain ordered indexes and labels.');
            }
            foreach ($record['indexes'] as $index) {
                if (!is_int($index) || !array_key_exists($index, $variables)) {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SPSS value-label indexes must refer to a source variable.');
                }
            }
            foreach ($record['labels'] as $label) {
                if (!is_array($label) || !is_string($label['label'] ?? null) || !array_key_exists('value', $label)) {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every SPSS value label must contain a typed value and string label.');
                }
                $this->dictionaryValue($label['value']);
            }
        }

        return V3MetadataPlan::fromSourceIfPresent($source);
    }

    /**
     * @param list<array<string, mixed>> $sourceVariables
     */
    private function storeCatalogue(
        string $datasetName,
        array $sourceVariables,
        MySqlWideTableDefinition $definition,
    ): void {
        $dataset = $this->requiredStatement(
            'INSERT INTO datasets (dataset_name, table_name) VALUES (?, ?)',
            'dataset catalogue',
        );
        $variable = $this->requiredStatement(
            'INSERT INTO variables (dataset_name, ordinal, source_name, column_name, storage_kind, source_width, format_family, format_width, format_decimals, write_format_family, write_format_width, write_format_decimals, label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'variable catalogue',
        );

        $dataset->execute([$datasetName, $definition->tableName]);
        foreach ($definition->columns as $index => $column) {
            $source = $sourceVariables[$index];
            $variable->execute([
                $datasetName,
                $index + 1,
                $column['sourceName'],
                $column['columnName'],
                $column['storageKind'],
                $this->integerField($source, 'width', 0),
                $this->printFormatField($source, 'formatFamily', 5),
                $this->printFormatField($source, 'formatWidth', 8),
                $this->printFormatField($source, 'formatDecimals', 0),
                $this->writeFormatField($source, 'writeFormatFamily', 5),
                $this->writeFormatField($source, 'writeFormatWidth', 8),
                $this->writeFormatField($source, 'writeFormatDecimals', 0),
                is_string($source['label'] ?? null) ? $source['label'] : null,
            ]);
        }
    }
    private function storeWeightVariable(
        string $datasetName,
        mixed $weightVariableName,
        MySqlWideTableDefinition $definition,
    ): void {
        if ($weightVariableName === null) {
            return;
        }
        if (!is_string($weightVariableName) || $weightVariableName === '') {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The SPSS weight-variable reference must be a non-empty source variable name.');
        }
        foreach ($definition->columns as $index => $column) {
            if ($column['sourceName'] === $weightVariableName) {
                $this->requiredStatement(
                    'INSERT INTO dataset_weight_variables (dataset_name, variable_ordinal) VALUES (?, ?)',
                    'weight-variable catalogue',
                )->execute([$datasetName, $index + 1]);

                return;
            }
        }

        throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The SPSS weight-variable reference must name a source variable.');
    }


    /**
     * @param list<mixed> $rows
     */
    private function insertCases(MySqlWideTableDefinition $definition, array $rows): void
    {
        $quote = chr(96);
        $columns = array_merge(['__case_ordinal'], array_column($definition->columns, 'columnName'));
        $quotedColumns = array_map(
            static fn(string $column): string => chr(96) . str_replace(chr(96), chr(96) . chr(96), $column) . chr(96),
            $columns,
        );
        $parameters = array_map(static fn(int $index): string => ':value_' . $index, array_keys($columns));
        $statement = $this->requiredStatement(
            'INSERT INTO ' . $quote . str_replace($quote, $quote . $quote, $definition->tableName) . $quote . ' ('
            . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $parameters) . ')',
            'wide-table data',
        );

        foreach ($rows as $caseOrdinal => $row) {
            if (!is_array($row)) {
                throw new UnsupportedOperation(
                    DiagnosticCode::InvalidSourceDataset,
                    'Every SPSS case must be an ordered value list or source-name map.',
                );
            }
            $values = ['value_0' => $caseOrdinal + 1];
            foreach ($definition->columns as $index => $column) {
                $value = array_key_exists($column['sourceName'], $row)
                    ? $row[$column['sourceName']]
                    : ($row[$index] ?? null);
                $values['value_' . ($index + 1)] = $this->caseValue($value, $column['storageKind']);
            }
            $statement->execute($values);
        }
    }

    /** @param array<string, mixed> $source */
    private function storeDatasetMetadata(string $datasetName, array $source): void
    {
        if (is_string($source['fileLabel'] ?? null)) {
            $this->requiredStatement(
                'INSERT INTO dataset_metadata (dataset_name, meta_key, meta_value) VALUES (?, ?, ?)',
                'file-label metadata',
            )->execute([$datasetName, 'file_label', $source['fileLabel']]);
        }

        $documents = $source['documents'] ?? [];
        if (!is_array($documents) || !array_is_list($documents)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SPSS documents must be an ordered list.');
        }
        if ($documents === []) {
            return;
        }
        $statement = $this->requiredStatement(
            'INSERT INTO documents (dataset_name, ordinal, text) VALUES (?, ?, ?)',
            'document metadata',
        );
        foreach ($documents as $ordinal => $text) {
            if (!is_string($text)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SPSS documents must contain strings.');
            }
            $statement->execute([$datasetName, $ordinal + 1, $text]);
        }
    }

    /** @param array<string, mixed> $source */
    private function storeTechnicalMetadata(string $datasetName, array $source): void
    {
        $technical = $source['technicalMetadata'] ?? null;
        if (!is_array($technical)) {
            return;
        }
        $sourceFormat = $technical['sourceFormat'] ?? null;
        $encoding = $technical['encoding'] ?? null;
        if (!is_string($sourceFormat) || $sourceFormat === '' || !is_string($encoding) || $encoding === '') {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'V3 technical metadata requires a non-empty source format and encoding.');
        }
        $this->requiredStatement(
            'INSERT INTO file_technical_metadata (dataset_name, source_format, record_type, source_version, provenance, encoding, product_name, raw_creation_date, raw_creation_time, case_count, nominal_case_size, layout_code, compression, compression_bias, machine_code, floating_point_representation, endianness, character_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'technical metadata',
        )->execute([
            $datasetName,
            $sourceFormat,
            $this->technicalString($technical['recordType'] ?? null),
            $this->technicalString($technical['sourceVersion'] ?? null),
            $this->technicalString($technical['provenance'] ?? null),
            $encoding,
            $this->technicalString($technical['productName'] ?? null),
            $this->technicalString($technical['rawCreationDate'] ?? null),
            $this->technicalString($technical['rawCreationTime'] ?? null),
            $this->technicalInt($technical['caseCount'] ?? null),
            $this->technicalInt($technical['nominalCaseSize'] ?? null),
            $this->technicalInt($technical['layoutCode'] ?? null),
            $this->technicalInt($technical['compression'] ?? null),
            $this->technicalFloat($technical['compressionBias'] ?? null),
            $this->technicalInt($technical['machineCode'] ?? null),
            $this->technicalInt($technical['floatingPointRepresentation'] ?? null),
            $this->technicalInt($technical['endianness'] ?? null),
            $this->technicalInt($technical['characterCode'] ?? null),
        ]);
    }

    /** @param mixed $displayParameters */
    private function storeDisplayMetadata(string $datasetName, mixed $displayParameters): void
    {
        if (!is_array($displayParameters) || !array_is_list($displayParameters)) {
            return;
        }
        $statement = null;
        foreach ($displayParameters as $ordinal => $display) {
            if (!is_array($display) || !is_int($display['measure'] ?? null) || !is_int($display['columns'] ?? null) || !is_int($display['alignment'] ?? null)) {
                continue;
            }
            $statement ??= $this->requiredStatement(
                'INSERT INTO variable_display_metadata (dataset_name, variable_ordinal, measurement_level, display_width, alignment) VALUES (?, ?, ?, ?, ?)',
                'variable display metadata',
            );
            $statement->execute([$datasetName, $ordinal + 1, $display['measure'], $display['columns'], $display['alignment']]);
        }
    }

    /**
     * @param list<array<string, mixed>> $sourceVariables
     * @param mixed $sourceValueLabels
     */
    private function storeDictionaryMetadata(string $datasetName, array $sourceVariables, mixed $sourceValueLabels): void
    {
        $missing = null;
        $missingValue = null;
        foreach ($sourceVariables as $index => $variable) {
            $format = $variable['missingFormat'] ?? 0;
            if (!is_int($format) || $format === 0) {
                continue;
            }
            $values = $variable['missingValues'] ?? [];
            if (!is_array($values) || !array_is_list($values)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SPSS user-missing values must be an ordered list.');
            }
            $missing ??= $this->requiredStatement(
                'INSERT INTO missing_rules (dataset_name, variable_ordinal, missing_format) VALUES (?, ?, ?)',
                'missing-rule metadata',
            );
            $missingValue ??= $this->requiredStatement(
                'INSERT INTO missing_rule_values (dataset_name, variable_ordinal, ordinal, value_kind, numeric_value, text_value) VALUES (?, ?, ?, ?, ?, ?)',
                'missing-rule-value metadata',
            );
            $missing->execute([$datasetName, $index + 1, $format]);
            foreach ($values as $ordinal => $value) {
                [$kind, $numeric, $text] = $this->dictionaryValue($value);
                $missingValue->execute([$datasetName, $index + 1, $ordinal + 1, $kind, $numeric, $text]);
            }
        }

        if (!is_array($sourceValueLabels) || !array_is_list($sourceValueLabels)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SPSS value-label records must be an ordered list.');
        }
        $valueLabel = null;
        foreach ($sourceValueLabels as $record) {
            if (!is_array($record)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every SPSS value-label record must be an array.');
            }
            $indexes = $record['indexes'] ?? null;
            $labels = $record['labels'] ?? null;
            if (!is_array($indexes) || !array_is_list($indexes) || !is_array($labels) || !array_is_list($labels)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SPSS value-label records must contain ordered indexes and labels.');
            }
            foreach ($indexes as $index) {
                if (!is_int($index) || !array_key_exists($index, $sourceVariables)) {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SPSS value-label indexes must refer to a source variable.');
                }
                foreach ($labels as $ordinal => $label) {
                    if (!is_array($label) || !is_string($label['label'] ?? null) || !array_key_exists('value', $label)) {
                        throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every SPSS value label must contain a typed value and string label.');
                    }
                    $valueLabel ??= $this->requiredStatement(
                        'INSERT INTO value_labels (dataset_name, variable_ordinal, ordinal, value_kind, numeric_value, text_value, label) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        'value-label metadata',
                    );
                    [$kind, $numeric, $text] = $this->dictionaryValue($label['value']);
                    $valueLabel->execute([$datasetName, $index + 1, $ordinal + 1, $kind, $numeric, $text, $label['label']]);
                }
            }
        }
    }

    /** @return array{string, string|null, string|null} */
    private function dictionaryValue(mixed $value): array
    {
        if (is_string($value)) {
            return ['text', null, $value];
        }
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value)) {
                throw new UnsupportedOperation(
                    DiagnosticCode::TargetCapabilityExceeded,
                    'The Dolt profile rejects non-finite SPSS numeric values before mutation.',
                );
            }

            return ['numeric', Binary64::encode($value), null];
        }
        throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'SPSS dictionary values must be strings or binary64 numbers.');
    }

    private function technicalString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function technicalInt(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function technicalFloat(mixed $value): ?float
    {
        return is_float($value) || is_int($value) ? (float) $value : null;
    }

    public function compensateFailure(string $datasetName, MySqlWideTableDefinition $definition): void
    {
        try {
            $datasetId = $definition->normativeDatasetId;
            if ($datasetId === null) {
                throw new \RuntimeException('The import attempt has no normative dataset ownership token.');
            }
            $this->assertOwnedNormativeDataset($datasetId, $datasetName, $definition->tableName);
            $this->removeNormativeDataset($datasetId);

            // This exact normative dataset ID proves that this attempt created
            // the matching compatibility rows and physical relation.
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
                'dataset_weight_variables',
                'variables',
                'datasets',
            ] as $table) {
                $sql = 'DELETE FROM ' . $table . ' WHERE dataset_name = ?';
                if ($table === 'datasets') {
                    $sql .= ' AND table_name = ?';
                }
                $statement = $this->pdo->prepare($sql);
                if ($statement !== false) {
                    $statement->execute($table === 'datasets'
                        ? [$datasetName, $definition->tableName]
                        : [$datasetName]);
                }
            }
            $this->dropPhysicalTable($definition);
        } catch (Throwable $cleanupFailure) {
            throw new \RuntimeException(
                'MySQL-family import cleanup failed; the target may require manual inspection: ' . $definition->tableName,
                previous: $cleanupFailure,
            );
        }
    }

    private function assertOwnedNormativeDataset(
        string $datasetId,
        string $datasetName,
        string $tableName,
    ): void {
        $dataset = $this->pdo->prepare(
            'SELECT dataset_name, physical_table_name FROM dataset WHERE dataset_id = ?',
        );
        if ($dataset === false) {
            throw new \RuntimeException('Could not verify normative dataset ownership.');
        }
        $dataset->execute([$datasetId]);
        $rows = $dataset->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1
            || ($rows[0]['dataset_name'] ?? null) !== $datasetName
            || ($rows[0]['physical_table_name'] ?? null) !== $tableName
        ) {
            throw new \RuntimeException('The normative dataset ownership token does not match this import attempt.');
        }
    }

    private function removeNormativeDataset(string $datasetId): void
    {
        foreach ([
            'UPDATE fidelity_event SET dataset_id = NULL WHERE dataset_id = ?',
            'DELETE FROM multiple_response_member WHERE multiple_response_set_id IN (SELECT multiple_response_set_id FROM multiple_response_set WHERE dataset_id = ?)',
            'DELETE FROM multiple_response_set WHERE dataset_id = ?',
            'DELETE FROM variable_set_member WHERE variable_set_id IN (SELECT variable_set_id FROM variable_set WHERE dataset_id = ?)',
            'DELETE FROM variable_set WHERE dataset_id = ?',
            'DELETE FROM missing_rule WHERE variable_id IN (SELECT variable_id FROM variable WHERE dataset_id = ?)',
            'DELETE FROM variable_value_label_set WHERE variable_id IN (SELECT variable_id FROM variable WHERE dataset_id = ?)',
            'DELETE FROM value_label WHERE value_label_set_id IN (SELECT value_label_set_id FROM value_label_set WHERE dataset_id = ?)',
            'DELETE FROM value_label_set WHERE dataset_id = ?',
            'DELETE FROM variable_attribute WHERE variable_id IN (SELECT variable_id FROM variable WHERE dataset_id = ?)',
            'DELETE FROM dataset_attribute WHERE dataset_id = ?',
            'DELETE FROM document WHERE dataset_id = ?',
            'DELETE FROM dataset_weight_variable WHERE dataset_id = ?',
            'DELETE FROM variable WHERE dataset_id = ?',
            'DELETE FROM dataset WHERE dataset_id = ?',
        ] as $sql) {
            $statement = $this->pdo->prepare($sql);
            if ($statement === false) {
                throw new \RuntimeException('Could not prepare normative Dolt cleanup.');
            }
            $statement->execute([$datasetId]);
        }
    }

    private function dropPhysicalTable(MySqlWideTableDefinition $definition): void
    {
        $quote = chr(96);
        $this->pdo->exec(
            'DROP TABLE IF EXISTS ' . $quote . str_replace($quote, $quote . $quote, $definition->tableName) . $quote,
        );
    }

    private function requiredStatement(string $sql, string $description): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new UnsupportedOperation(
                DiagnosticCode::InvalidSourceDataset,
                'The MySQL-family profile could not prepare the ' . $description . ' statement.',
            );
        }

        return $statement;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function integerField(array $source, string $key, int $default): int
    {
        return is_int($source[$key] ?? null) ? $source[$key] : $default;
    }

    /** @param array<string, mixed> $source */
    private function printFormatField(array $source, string $key, int $default): int
    {
        return is_int($source[$key] ?? null) ? $source[$key] : $default;
    }

    /** @param array<string, mixed> $source */
    private function writeFormatField(array $source, string $key, int $default): int
    {
        if (is_int($source[$key] ?? null)) {
            return $source[$key];
        }
        // A pre-fidelity source with no format fields at all used the default
        // SPSS format. Explicit print fields without write fields are rejected;
        // copying those values would silently lose format fidelity.
        if (!isset($source['formatFamily']) && !isset($source['formatWidth']) && !isset($source['formatDecimals'])) {
            return $default;
        }
        throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every source variable with explicit print formats must provide independent write format fields.');
    }

    private function caseValue(mixed $value, string $storageKind): int|string|null
    {
        if ($storageKind === 'string') {
            if (!is_string($value)) {
                throw new UnsupportedOperation(
                    DiagnosticCode::InvalidSourceDataset,
                    'SPSS string values must be non-null strings.',
                );
            }

            return $value;
        }
        if ($value === null || is_int($value)) {
            return $value;
        }
        if (!is_float($value)) {
            throw new UnsupportedOperation(
                DiagnosticCode::InvalidSourceDataset,
                'SPSS numeric values must be binary64 numbers or system-missing NULL.',
            );
        }
        if (!is_finite($value)) {
            throw new UnsupportedOperation(
                DiagnosticCode::TargetCapabilityExceeded,
                'The Dolt profile rejects non-finite SPSS numeric values before mutation.',
            );
        }

        // A 17-digit decimal representation round-trips every IEEE-754 binary64.
        return sprintf('%.17g', $value);
    }
}
