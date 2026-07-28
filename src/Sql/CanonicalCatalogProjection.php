<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\Binary64;
use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\SpssMissingValueSentinel;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Projects the authoritative singular OpenStatSpec catalog into the temporary
 * plural compatibility catalog consumed by the existing typed exporters.
 * The projection is rebuilt before every export, so legacy-only edits cannot
 * affect output and canonical edits always do.
 */
final readonly class CanonicalCatalogProjection
{
    public function __construct(private PDO $pdo) {}

    public function synchronize(string $datasetName): void
    {
        (new NormativeCatalog($this->pdo))->createTables();
        $dataset = $this->one('SELECT dataset_id, source_format, physical_table_name, dataset_label, source_encoding FROM dataset WHERE dataset_name = ?', [$datasetName]);
        $datasetId = $this->requiredString($dataset['dataset_id'] ?? null, 'Canonical dataset ID');
        $tableName = $this->requiredString($dataset['physical_table_name'] ?? null, 'Canonical physical table name');

        $this->pdo->beginTransaction();
        try {
            $this->clearLegacyMetadata($datasetName);
            $this->ensureLegacyDataset($datasetName, $tableName);
            $variables = $this->projectVariables($datasetId, $datasetName);
            $this->projectDatasetMetadata($datasetId, $datasetName, $dataset);
            $this->projectWeight($datasetId, $datasetName);
            $this->projectValueLabels($datasetId, $datasetName);
            $this->projectMissingRules($datasetId, $datasetName);
            $this->projectAttributes($datasetId, $datasetName);
            $this->projectDocuments($datasetId, $datasetName);
            $this->projectSets($datasetId, $datasetName);
            $this->projectMultipleResponseSets($datasetId, $datasetName);
            if ($variables === 0) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The canonical dataset has no variables.');
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function clearLegacyMetadata(string $datasetName): void
    {
        foreach ([
            'multiple_response_set_members', 'multiple_response_sets',
            'variable_set_members', 'variable_sets', 'variable_attributes',
            'file_attributes', 'variable_roles', 'variable_display_metadata',
            'missing_rule_values', 'missing_rules', 'value_labels', 'documents',
            'dataset_metadata', 'dataset_weight_variables',
            'variables',
        ] as $table) {
            $this->statement('DELETE FROM ' . $table . ' WHERE dataset_name = ?')->execute([$datasetName]);
        }
    }

    private function ensureLegacyDataset(string $datasetName, string $tableName): void
    {
        $exists = $this->statement('SELECT 1 FROM datasets WHERE dataset_name = ?');
        $exists->execute([$datasetName]);
        if ($exists->fetchColumn() === false) {
            $this->statement('INSERT INTO datasets (dataset_name, table_name) VALUES (?, ?)')->execute([$datasetName, $tableName]);
            return;
        }
        $this->statement('UPDATE datasets SET table_name = ? WHERE dataset_name = ?')->execute([$tableName, $datasetName]);
    }

    private function projectVariables(string $datasetId, string $datasetName): int
    {
        $rows = $this->all('SELECT source_ordinal, source_name, physical_name, storage_kind, declared_string_width, variable_label, print_format_family, print_format_width, print_format_decimals, write_format_family, write_format_width, write_format_decimals, measurement_level, variable_role, display_width, display_alignment FROM variable WHERE dataset_id = ? ORDER BY source_ordinal', [$datasetId]);
        $variable = $this->statement('INSERT INTO variables (dataset_name, ordinal, source_name, column_name, storage_kind, source_width, format_family, format_width, format_decimals, write_format_family, write_format_width, write_format_decimals, label) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $display = $this->statement('INSERT INTO variable_display_metadata (dataset_name, variable_ordinal, measurement_level, display_width, alignment) VALUES (?, ?, ?, ?, ?)');
        $role = $this->statement('INSERT INTO variable_roles (dataset_name, variable_ordinal, role) VALUES (?, ?, ?)');
        foreach ($rows as $row) {
            $ordinal = (int) $row['source_ordinal'];
            $variable->execute([
                $datasetName, $ordinal, $row['source_name'], $row['physical_name'], $row['storage_kind'],
                $row['declared_string_width'] === null ? 0 : (int) $row['declared_string_width'],
                (int) $row['print_format_family'], (int) $row['print_format_width'], (int) $row['print_format_decimals'],
                (int) $row['write_format_family'], (int) $row['write_format_width'], (int) $row['write_format_decimals'],
                $row['variable_label'],
            ]);
            $display->execute([$datasetName, $ordinal, (int) ($row['measurement_level'] ?? 0), (int) ($row['display_width'] ?? 8), (int) ($row['display_alignment'] ?? 0)]);
            $role->execute([$datasetName, $ordinal, (int) ($row['variable_role'] ?? 0)]);
        }
        return count($rows);
    }

    /** @param array<string, mixed> $dataset */
    private function projectDatasetMetadata(string $datasetId, string $datasetName, array $dataset): void
    {
        if (is_string($dataset['dataset_label'] ?? null)) {
            $this->statement('INSERT INTO dataset_metadata (dataset_name, meta_key, meta_value) VALUES (?, ?, ?)')->execute([$datasetName, 'file_label', $dataset['dataset_label']]);
        }
        $format = $this->requiredString($dataset['source_format'] ?? null, 'Canonical source format');
        $encoding = is_string($dataset['source_encoding'] ?? null) && $dataset['source_encoding'] !== '' ? $dataset['source_encoding'] : 'UTF-8';
        $technical = $this->statement('SELECT 1 FROM file_technical_metadata WHERE dataset_name = ?');
        $technical->execute([$datasetName]);
        if ($technical->fetchColumn() !== false) {
            $this->statement('UPDATE file_technical_metadata SET source_format = ?, record_type = ?, encoding = ?, compression = ? WHERE dataset_name = ?')->execute([$format, $format === 'zsav' ? '$FL3' : '$FL2', $encoding, $format === 'zsav' ? 2 : 1, $datasetName]);
        } else {
            $this->statement('INSERT INTO file_technical_metadata (dataset_name, source_format, record_type, source_version, provenance, encoding, product_name, raw_creation_date, raw_creation_time, case_count, nominal_case_size, layout_code, compression, compression_bias, machine_code, floating_point_representation, endianness, character_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $datasetName, $format, $format === 'zsav' ? '$FL3' : '$FL2', null, null, $encoding, null, null, null, null, null, null, $format === 'zsav' ? 2 : 1, null, null, null, null, null,
            ]);
        }
    }

    private function projectWeight(string $datasetId, string $datasetName): void
    {
        $row = $this->optionalOne('SELECT variable.source_ordinal FROM dataset_weight_variable weight JOIN variable ON variable.variable_id = weight.variable_id WHERE weight.dataset_id = ?', [$datasetId]);
        if ($row !== null) {
            $this->statement('INSERT INTO dataset_weight_variables (dataset_name, variable_ordinal) VALUES (?, ?)')->execute([$datasetName, (int) $row['source_ordinal']]);
        }
    }

    private function projectValueLabels(string $datasetId, string $datasetName): void
    {
        $rows = $this->all('SELECT variable.source_ordinal, label.ordinal, label.code_kind, label.numeric_code, label.string_code, label.label FROM variable JOIN variable_value_label_set link ON link.variable_id = variable.variable_id JOIN value_label label ON label.value_label_set_id = link.value_label_set_id WHERE variable.dataset_id = ? ORDER BY variable.source_ordinal, label.ordinal', [$datasetId]);
        $insert = $this->statement('INSERT INTO value_labels (dataset_name, variable_ordinal, ordinal, value_kind, numeric_value, text_value, label) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as $row) {
            $isString = $row['code_kind'] === 'string';
            $insert->execute([$datasetName, (int) $row['source_ordinal'], (int) $row['ordinal'], $isString ? 'text' : 'numeric', $isString ? null : $row['numeric_code'], $isString ? $row['string_code'] : null, $row['label']]);
        }
    }

    private function projectMissingRules(string $datasetId, string $datasetName): void
    {
        $rows = $this->all('SELECT variable.source_ordinal, rule.ordinal, rule.rule_kind, rule.code_kind, rule.numeric_value, rule.string_value, rule.numeric_lower, rule.numeric_upper, rule.lower_special, rule.upper_special FROM variable JOIN missing_rule rule ON rule.variable_id = variable.variable_id WHERE variable.dataset_id = ? ORDER BY variable.source_ordinal, rule.ordinal', [$datasetId]);
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['source_ordinal']][] = $row;
        }
        $ruleInsert = $this->statement('INSERT INTO missing_rules (dataset_name, variable_ordinal, missing_format) VALUES (?, ?, ?)');
        $valueInsert = $this->statement('INSERT INTO missing_rule_values (dataset_name, variable_ordinal, ordinal, value_kind, numeric_value, text_value) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($grouped as $variableOrdinal => $rules) {
            $first = $rules[0];
            $range = $first['rule_kind'] === 'numeric_range';
            $format = $range ? (count($rules) > 1 ? -3 : -2) : count($rules);
            $ruleInsert->execute([$datasetName, $variableOrdinal, $format]);
            $values = [];
            if ($range) {
                $values[] = ['numeric', $first['lower_special'] === 'LOWEST' ? Binary64::encode(SpssMissingValueSentinel::lowest()) : $first['numeric_lower'], null];
                $values[] = ['numeric', $first['upper_special'] === 'HIGHEST' ? Binary64::encode(SpssMissingValueSentinel::highest()) : $first['numeric_upper'], null];
                $rules = array_slice($rules, 1);
            }
            foreach ($rules as $rule) {
                $isString = $rule['code_kind'] === 'string';
                $values[] = [$isString ? 'text' : 'numeric', $isString ? null : $rule['numeric_value'], $isString ? $rule['string_value'] : null];
            }
            foreach ($values as $index => $value) {
                $valueInsert->execute([$datasetName, $variableOrdinal, $index + 1, $value[0], $value[1], $value[2]]);
            }
        }
    }

    private function projectAttributes(string $datasetId, string $datasetName): void
    {
        $file = $this->statement('INSERT INTO file_attributes (dataset_name, attribute_name, ordinal, value) VALUES (?, ?, ?, ?)');
        foreach ($this->all('SELECT attribute_name, array_ordinal, attribute_value FROM dataset_attribute WHERE dataset_id = ? ORDER BY attribute_name, array_ordinal', [$datasetId]) as $row) {
            $file->execute([$datasetName, $row['attribute_name'], (int) $row['array_ordinal'], $row['attribute_value']]);
        }
        $variable = $this->statement('INSERT INTO variable_attributes (dataset_name, variable_ordinal, attribute_name, ordinal, value) VALUES (?, ?, ?, ?, ?)');
        foreach ($this->all('SELECT source.source_ordinal, attribute.attribute_name, attribute.array_ordinal, attribute.attribute_value FROM variable_attribute attribute JOIN variable source ON source.variable_id = attribute.variable_id WHERE source.dataset_id = ? ORDER BY source.source_ordinal, attribute.attribute_name, attribute.array_ordinal', [$datasetId]) as $row) {
            $variable->execute([$datasetName, (int) $row['source_ordinal'], $row['attribute_name'], (int) $row['array_ordinal'], $row['attribute_value']]);
        }
    }

    private function projectDocuments(string $datasetId, string $datasetName): void
    {
        $insert = $this->statement('INSERT INTO documents (dataset_name, ordinal, text) VALUES (?, ?, ?)');
        foreach ($this->all('SELECT source_ordinal, document_text FROM document WHERE dataset_id = ? ORDER BY source_ordinal', [$datasetId]) as $row) {
            $insert->execute([$datasetName, (int) $row['source_ordinal'], $row['document_text']]);
        }
    }

    private function projectSets(string $datasetId, string $datasetName): void
    {
        $setInsert = $this->statement('INSERT INTO variable_sets (dataset_name, set_ordinal, name) VALUES (?, ?, ?)');
        $memberInsert = $this->statement('INSERT INTO variable_set_members (dataset_name, set_ordinal, member_ordinal, variable_ordinal) VALUES (?, ?, ?, ?)');
        foreach ($this->all('SELECT variable_set_id, source_ordinal, set_name FROM variable_set WHERE dataset_id = ? ORDER BY source_ordinal', [$datasetId]) as $set) {
            $ordinal = (int) $set['source_ordinal'];
            $setInsert->execute([$datasetName, $ordinal, $set['set_name']]);
            foreach ($this->all('SELECT member.source_ordinal, variable.source_ordinal AS variable_ordinal FROM variable_set_member member JOIN variable ON variable.variable_id = member.variable_id WHERE member.variable_set_id = ? ORDER BY member.source_ordinal', [$set['variable_set_id']]) as $member) {
                $memberInsert->execute([$datasetName, $ordinal, (int) $member['source_ordinal'], (int) $member['variable_ordinal']]);
            }
        }
    }

    private function projectMultipleResponseSets(string $datasetId, string $datasetName): void
    {
        $setInsert = $this->statement('INSERT INTO multiple_response_sets (dataset_name, set_ordinal, name, set_type, label, counted_value_kind, counted_numeric_value, counted_text_value, category_labels, label_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $memberInsert = $this->statement('INSERT INTO multiple_response_set_members (dataset_name, set_ordinal, member_ordinal, variable_ordinal) VALUES (?, ?, ?, ?)');
        foreach ($this->all('SELECT multiple_response_set_id, source_ordinal, set_name, set_label, set_kind, counted_value_kind, counted_numeric_value, counted_string_value, category_label_behavior, label_source FROM multiple_response_set WHERE dataset_id = ? ORDER BY source_ordinal', [$datasetId]) as $set) {
            $ordinal = (int) $set['source_ordinal'];
            $kind = $set['counted_value_kind'] === 'string' ? 'text' : $set['counted_value_kind'];
            $setInsert->execute([$datasetName, $ordinal, $set['set_name'], $set['set_kind'] === 'MD' ? 'dichotomy' : 'category', $set['set_label'], $kind, $set['counted_numeric_value'], $set['counted_string_value'], $set['category_label_behavior'], $set['label_source']]);
            foreach ($this->all('SELECT member.source_ordinal, variable.source_ordinal AS variable_ordinal FROM multiple_response_member member JOIN variable ON variable.variable_id = member.variable_id WHERE member.multiple_response_set_id = ? ORDER BY member.source_ordinal', [$set['multiple_response_set_id']]) as $member) {
                $memberInsert->execute([$datasetName, $ordinal, (int) $member['source_ordinal'], (int) $member['variable_ordinal']]);
            }
        }
    }

    /**
     * @param list<mixed> $parameters
     * @return array<string, mixed>
     */
    private function one(string $sql, array $parameters): array
    {
        $row = $this->optionalOne($sql, $parameters);
        if ($row === null) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'The requested canonical dataset is not present.');
        }
        return $row;
    }

    /**
     * @param list<mixed> $parameters
     * @return array<string, mixed>|null
     */
    private function optionalOne(string $sql, array $parameters): ?array
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param list<mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function all(string $sql, array $parameters): array
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function statement(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Could not prepare a canonical catalog projection statement.');
        }
        return $statement;
    }

    private function requiredString(mixed $value, string $description): string
    {
        if (!is_string($value) || $value === '') {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, $description . ' is missing.');
        }
        return $value;
    }
}
