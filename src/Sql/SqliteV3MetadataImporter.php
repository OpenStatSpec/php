<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use PDO;

/** Persists canonically validated V3 SPSS metadata beside a strict wide data table. */
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
        $this->storeValidated($datasetName, V3MetadataPlan::fromSource($source));
    }

    public function storeValidated(string $datasetName, V3MetadataPlan $metadata): void
    {
        $this->storeRoles($datasetName, $metadata->roles);
        $this->storeVariableAttributes($datasetName, $metadata->variableAttributes);
        $this->storeFileAttributes($datasetName, $metadata->fileAttributes);
        $this->storeVariableSets($datasetName, $metadata->variableSets);
        $this->storeMultipleResponseSets($datasetName, $metadata->multipleResponseSets);
    }

    /** @param list<int> $roles */
    private function storeRoles(string $datasetName, array $roles): void
    {
        $statement = $this->pdo->prepare('INSERT INTO variable_roles (dataset_name, variable_ordinal, role) VALUES (?, ?, ?)');
        foreach ($roles as $ordinal => $role) {
            $statement->execute([$datasetName, $ordinal + 1, $role]);
        }
    }

    /** @param list<array{variableOrdinal: int, name: string, values: list<string>}> $attributes */
    private function storeVariableAttributes(string $datasetName, array $attributes): void
    {
        $statement = $this->pdo->prepare('INSERT INTO variable_attributes (dataset_name, variable_ordinal, attribute_name, ordinal, value) VALUES (?, ?, ?, ?, ?)');
        foreach ($attributes as $attribute) {
            foreach ($attribute['values'] as $ordinal => $value) {
                $statement->execute([$datasetName, $attribute['variableOrdinal'], $attribute['name'], $ordinal + 1, $value]);
            }
        }
    }

    /** @param list<array{name: string, values: list<string>}> $attributes */
    private function storeFileAttributes(string $datasetName, array $attributes): void
    {
        $statement = $this->pdo->prepare('INSERT INTO file_attributes (dataset_name, attribute_name, ordinal, value) VALUES (?, ?, ?, ?)');
        foreach ($attributes as $attribute) {
            foreach ($attribute['values'] as $ordinal => $value) {
                $statement->execute([$datasetName, $attribute['name'], $ordinal + 1, $value]);
            }
        }
    }

    /** @param list<array{name: string, members: list<int>}> $sets */
    private function storeVariableSets(string $datasetName, array $sets): void
    {
        $setStatement = $this->pdo->prepare('INSERT INTO variable_sets (dataset_name, set_ordinal, name) VALUES (?, ?, ?)');
        $memberStatement = $this->pdo->prepare('INSERT INTO variable_set_members (dataset_name, set_ordinal, member_ordinal, variable_ordinal) VALUES (?, ?, ?, ?)');
        foreach ($sets as $setOrdinal => $set) {
            $setStatement->execute([$datasetName, $setOrdinal + 1, $set['name']]);
            foreach ($set['members'] as $memberOrdinal => $variableOrdinal) {
                $memberStatement->execute([$datasetName, $setOrdinal + 1, $memberOrdinal + 1, $variableOrdinal]);
            }
        }
    }

    /** @param list<array{name: string, type: string, label: ?string, countedValue: int|float|string|null, categoryLabels: string, labelSource: string, members: list<int>}> $sets */
    private function storeMultipleResponseSets(string $datasetName, array $sets): void
    {
        $setStatement = $this->pdo->prepare('INSERT INTO multiple_response_sets (dataset_name, set_ordinal, name, set_type, label, counted_value_kind, counted_numeric_value, counted_text_value, category_labels, label_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $memberStatement = $this->pdo->prepare('INSERT INTO multiple_response_set_members (dataset_name, set_ordinal, member_ordinal, variable_ordinal) VALUES (?, ?, ?, ?)');
        foreach ($sets as $setOrdinal => $set) {
            $countedValue = $set['countedValue'];
            $setStatement->execute([
                $datasetName,
                $setOrdinal + 1,
                $set['name'],
                $set['type'],
                $set['label'],
                $countedValue === null ? null : (is_string($countedValue) ? 'text' : 'numeric'),
                is_int($countedValue) || is_float($countedValue) ? (float) $countedValue : null,
                is_string($countedValue) ? $countedValue : null,
                $set['categoryLabels'],
                $set['labelSource'],
            ]);
            foreach ($set['members'] as $memberOrdinal => $variableOrdinal) {
                $memberStatement->execute([$datasetName, $setOrdinal + 1, $memberOrdinal + 1, $variableOrdinal]);
            }
        }
    }
}
