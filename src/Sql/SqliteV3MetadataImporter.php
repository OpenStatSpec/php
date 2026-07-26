<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;

/** Persists V3 SPSS metadata that belongs beside the strict wide data table. */
final readonly class SqliteV3MetadataImporter
{
    public function __construct(private PDO $pdo) {}

    public static function createTables(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS variable_roles (dataset_name TEXT NOT NULL, variable_ordinal INTEGER NOT NULL, role INTEGER NOT NULL, PRIMARY KEY (dataset_name, variable_ordinal))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS file_attributes (dataset_name TEXT NOT NULL, attribute_name TEXT NOT NULL, ordinal INTEGER NOT NULL, value TEXT NOT NULL, PRIMARY KEY (dataset_name, attribute_name, ordinal))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS variable_attributes (dataset_name TEXT NOT NULL, variable_ordinal INTEGER NOT NULL, attribute_name TEXT NOT NULL, ordinal INTEGER NOT NULL, value TEXT NOT NULL, PRIMARY KEY (dataset_name, variable_ordinal, attribute_name, ordinal))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS variable_sets (dataset_name TEXT NOT NULL, set_ordinal INTEGER NOT NULL, name TEXT NOT NULL, PRIMARY KEY (dataset_name, set_ordinal), UNIQUE (dataset_name, name))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS variable_set_members (dataset_name TEXT NOT NULL, set_ordinal INTEGER NOT NULL, member_ordinal INTEGER NOT NULL, variable_ordinal INTEGER NOT NULL, PRIMARY KEY (dataset_name, set_ordinal, member_ordinal))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS multiple_response_sets (dataset_name TEXT NOT NULL, set_ordinal INTEGER NOT NULL, name TEXT NOT NULL, set_type TEXT NOT NULL, label TEXT NULL, counted_value_kind TEXT NULL, counted_numeric_value REAL NULL, counted_text_value TEXT NULL, category_labels TEXT NOT NULL, label_source TEXT NOT NULL, PRIMARY KEY (dataset_name, set_ordinal), UNIQUE (dataset_name, name))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS multiple_response_set_members (dataset_name TEXT NOT NULL, set_ordinal INTEGER NOT NULL, member_ordinal INTEGER NOT NULL, variable_ordinal INTEGER NOT NULL, PRIMARY KEY (dataset_name, set_ordinal, member_ordinal))');
    }

    /** @param array<string, mixed> $source */
    public function store(string $datasetName, array $source): void
    {
        $variables = $source['variables'] ?? null;
        if (!is_array($variables)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'V3 metadata requires a source variable list.');
        }

        $this->storeRoles($datasetName, $variables);
        $this->storeVariableAttributes($datasetName, $variables);
        $this->storeFileAttributes($datasetName, $this->list($source['fileAttributes'] ?? [], 'File attributes'));
        $ordinals = $this->variableOrdinals($variables);
        $this->storeVariableSets($datasetName, $this->list($source['variableSets'] ?? [], 'Variable sets'), $ordinals);
        $this->storeMultipleResponseSets($datasetName, $this->list($source['multipleResponseSets'] ?? [], 'Multiple-response sets'), $ordinals);
    }

    /** @param array<int, mixed> $variables */
    private function storeRoles(string $datasetName, array $variables): void
    {
        $statement = $this->pdo->prepare('INSERT INTO variable_roles (dataset_name, variable_ordinal, role) VALUES (?, ?, ?)');
        foreach ($variables as $ordinal => $variable) {
            $role = $this->field($variable, 'role');
            if (!is_int($role) || $role < 0 || $role > 5) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every variable must have a valid SPSS role.');
            }
            $statement->execute([$datasetName, $ordinal + 1, $role]);
        }
    }

    /** @param array<int, mixed> $variables */
    private function storeVariableAttributes(string $datasetName, array $variables): void
    {
        $statement = $this->pdo->prepare('INSERT INTO variable_attributes (dataset_name, variable_ordinal, attribute_name, ordinal, value) VALUES (?, ?, ?, ?, ?)');
        foreach ($variables as $variableOrdinal => $variable) {
            $attributes = $this->list($this->field($variable, 'attributes') ?? [], 'Variable attributes');
            foreach ($attributes as $attribute) {
                $name = $this->field($attribute, 'name');
                $values = $this->field($attribute, 'values');
                if (!is_string($name) || $name === '' || !is_array($values) || $values === []) {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every variable attribute must have a name and values.');
                }
                foreach ($values as $ordinal => $value) {
                    if (!is_string($value)) {
                        throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Variable attribute values must be strings.');
                    }
                    $statement->execute([$datasetName, $variableOrdinal + 1, $name, $ordinal + 1, $value]);
                }
            }
        }
    }

    /** @param array<int, mixed> $attributes */
    private function storeFileAttributes(string $datasetName, array $attributes): void
    {
        $statement = $this->pdo->prepare('INSERT INTO file_attributes (dataset_name, attribute_name, ordinal, value) VALUES (?, ?, ?, ?)');
        foreach ($attributes as $attribute) {
            $name = $this->field($attribute, 'name');
            $values = $this->field($attribute, 'values');
            if (!is_string($name) || $name === '' || !is_array($values) || $values === []) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every file attribute must have a name and values.');
            }
            foreach ($values as $ordinal => $value) {
                if (!is_string($value)) {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'File attribute values must be strings.');
                }
                $statement->execute([$datasetName, $name, $ordinal + 1, $value]);
            }
        }
    }

    /**
     * @param array<int, mixed> $sets
     * @param array<string, int> $variableOrdinals
     */
    private function storeVariableSets(string $datasetName, array $sets, array $variableOrdinals): void
    {
        $set = $this->pdo->prepare('INSERT INTO variable_sets (dataset_name, set_ordinal, name) VALUES (?, ?, ?)');
        $member = $this->pdo->prepare('INSERT INTO variable_set_members (dataset_name, set_ordinal, member_ordinal, variable_ordinal) VALUES (?, ?, ?, ?)');
        foreach ($sets as $setOrdinal => $source) {
            $name = $this->field($source, 'name');
            $members = $this->field($source, 'variableNames');
            if (!is_string($name) || $name === '' || !is_array($members)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every variable set must have a name and ordered members.');
            }
            $set->execute([$datasetName, $setOrdinal + 1, $name]);
            foreach ($members as $memberOrdinal => $variableName) {
                if (!is_string($variableName) || !isset($variableOrdinals[$variableName])) {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A variable set references an unknown source variable.');
                }
                $member->execute([$datasetName, $setOrdinal + 1, $memberOrdinal + 1, $variableOrdinals[$variableName]]);
            }
        }
    }

    /**
     * @param array<int, mixed> $sets
     * @param array<string, int> $variableOrdinals
     */
    private function storeMultipleResponseSets(string $datasetName, array $sets, array $variableOrdinals): void
    {
        $set = $this->pdo->prepare('INSERT INTO multiple_response_sets (dataset_name, set_ordinal, name, set_type, label, counted_value_kind, counted_numeric_value, counted_text_value, category_labels, label_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $member = $this->pdo->prepare('INSERT INTO multiple_response_set_members (dataset_name, set_ordinal, member_ordinal, variable_ordinal) VALUES (?, ?, ?, ?)');
        foreach ($sets as $setOrdinal => $source) {
            $name = $this->field($source, 'name');
            $type = $this->field($source, 'type');
            $members = $this->field($source, 'variableNames');
            $label = $this->field($source, 'label');
            $countedValue = $this->field($source, 'countedValue');
            $categoryLabels = $this->field($source, 'categoryLabels');
            $labelSource = $this->field($source, 'labelSource');
            if (!is_string($name) || $name === '' || !in_array($type, ['category', 'dichotomy'], true) || !is_array($members) || ($label !== null && !is_string($label)) || !in_array($categoryLabels, ['variable_labels', 'counted_values'], true) || !in_array($labelSource, ['set_label', 'variable_label'], true) || ($countedValue !== null && !is_int($countedValue) && !is_float($countedValue) && !is_string($countedValue))) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A multiple-response set is malformed.');
            }
            if (($type === 'category' && $countedValue !== null) || ($type === 'dichotomy' && ($countedValue === null || $countedValue === ''))) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A multiple-response set has an invalid counted value.');
            }
            $set->execute([
                $datasetName,
                $setOrdinal + 1,
                $name,
                $type,
                $label,
                $countedValue === null ? null : (is_string($countedValue) ? 'text' : 'numeric'),
                is_int($countedValue) || is_float($countedValue) ? (float) $countedValue : null,
                is_string($countedValue) ? $countedValue : null,
                $categoryLabels,
                $labelSource,
            ]);
            foreach ($members as $memberOrdinal => $variableName) {
                if (!is_string($variableName) || !isset($variableOrdinals[$variableName])) {
                    throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'A multiple-response set references an unknown source variable.');
                }
                $member->execute([$datasetName, $setOrdinal + 1, $memberOrdinal + 1, $variableOrdinals[$variableName]]);
            }
        }
    }

    /** @param array<int, mixed> $variables
     * @return array<string, int>
     */
    private function variableOrdinals(array $variables): array
    {
        $result = [];
        foreach ($variables as $ordinal => $variable) {
            $name = $this->field($variable, 'name');
            if (!is_string($name) || $name === '' || isset($result[$name])) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Source variable names must be unique for metadata references.');
            }
            $result[$name] = $ordinal + 1;
        }

        return $result;
    }

    /** @return array<int, mixed> */
    private function list(mixed $value, string $description): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, $description . ' must be a list.');
        }

        return $value;
    }

    private function field(mixed $source, string $name): mixed
    {
        return is_array($source) ? ($source[$name] ?? null) : null;
    }
}
