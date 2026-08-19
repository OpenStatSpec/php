<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Plan\Operation\AssignOperation;
use OpenStatSpec\Transformation\Plan\Operation\ConditionalAssignOperation;
use OpenStatSpec\Transformation\Plan\Operation\ExecuteOperation;
use OpenStatSpec\Transformation\Plan\Operation\RecodeOperation;
use OpenStatSpec\Transformation\Plan\Operation\ReplaceValueLabelsOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetFormatOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetMeasurementLevelOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetVariableLabelOperation;
use OpenStatSpec\Transformation\Plan\Recode\CopyResult;
use OpenStatSpec\Transformation\Plan\Recode\ExactMatch;
use OpenStatSpec\Transformation\Plan\Recode\LiteralResult;
use OpenStatSpec\Transformation\Plan\Recode\RangeMatch;
use OpenStatSpec\Transformation\Plan\Recode\Result;
use OpenStatSpec\Transformation\Plan\Recode\SystemMissingMatch;
use OpenStatSpec\Transformation\Plan\Recode\SystemMissingResult;
use OpenStatSpec\Transformation\Plan\Value\Binary64Value;
use OpenStatSpec\Transformation\Plan\Value\StringValue;
use OpenStatSpec\Transformation\Plan\Value\TypedValue;
use PDO;
use PDOStatement;

/** @internal Executes exactly one pre-bound operation inside the caller transaction. */
final readonly class InPlaceOperationExecutor
{
    private SqlPredicateCompiler $predicateCompiler;

    public function __construct(private Connection $connection)
    {
        $this->predicateCompiler = new SqlPredicateCompiler($connection->profile);
    }

    public function isFor(PDO $pdo): bool
    {
        return $this->connection->pdo === $pdo;
    }

    public function execute(BoundOperation $bound, DatasetBinding $dataset): void
    {
        if (!$this->connection->pdo->inTransaction()) {
            throw new \LogicException('An in-place operation requires the open apply transaction.');
        }

        $operation = $bound->operation;
        if ($operation instanceof AssignOperation) {
            $target = $this->target($bound);
            if (!$target->persisted) {
                $this->createNumericTarget($dataset, $target);
            }
            $parameters = [];
            $value = $this->predicateCompiler->operand($operation->value, $bound->schema, $parameters);
            CheckedPdo::execute($this->statement(
                'UPDATE ' . $dataset->qualifiedTable($this->connection->profile)
                . ' SET ' . $this->quote($target->physicalName) . ' = ' . $value,
            ), $parameters, 'The assignment UPDATE could not be executed.');
            return;
        }

        if ($operation instanceof ConditionalAssignOperation) {
            $parameters = [];
            $value = $this->predicateCompiler->operand($operation->value, $bound->schema, $parameters);
            $condition = $this->predicateCompiler->compile($operation->condition, $bound->schema, $parameters);
            CheckedPdo::execute($this->statement(
                'UPDATE ' . $dataset->qualifiedTable($this->connection->profile)
                . ' SET ' . $this->quote($this->target($bound)->physicalName) . ' = ' . $value
                . ' WHERE ' . $condition,
            ), $parameters, 'The conditional assignment UPDATE could not be executed.');
            return;
        }

        if ($operation instanceof RecodeOperation) {
            $target = $this->target($bound);
            if (!$target->persisted) {
                $this->createNumericTarget($dataset, $target);
            }
            $this->recode($operation, $bound, $dataset);
            return;
        }

        if ($operation instanceof SetVariableLabelOperation) {
            CheckedPdo::execute($this->statement(
                'UPDATE variable SET variable_label = ? WHERE variable_id = ? AND dataset_id = ?',
            ), [$operation->label, $this->target($bound)->variableId, $dataset->datasetId], 'The variable-label UPDATE could not be executed.');
            return;
        }

        if ($operation instanceof ReplaceValueLabelsOperation) {
            $this->replaceValueLabels($operation, $dataset, $this->target($bound));
            return;
        }

        if ($operation instanceof SetFormatOperation) {
            CheckedPdo::execute($this->statement(
                'UPDATE variable SET print_format_family = ?, print_format_width = ?, print_format_decimals = ?, '
                . 'write_format_family = ?, write_format_width = ?, write_format_decimals = ? '
                . 'WHERE variable_id = ? AND dataset_id = ?',
            ), [
                $operation->family,
                $operation->width,
                $operation->decimals,
                $operation->family,
                $operation->width,
                $operation->decimals,
                $this->target($bound)->variableId,
                $dataset->datasetId,
            ], 'The variable-format UPDATE could not be executed.');
            return;
        }

        if ($operation instanceof SetMeasurementLevelOperation) {
            CheckedPdo::execute($this->statement(
                'UPDATE variable SET measurement_level = ? WHERE variable_id = ? AND dataset_id = ?',
            ), [$operation->level, $this->target($bound)->variableId, $dataset->datasetId], 'The measurement-level UPDATE could not be executed.');
            return;
        }

        if ($operation instanceof ExecuteOperation) {
            return;
        }

        throw TransformationFailure::at('plan_schema_invalid', '$.operations', 'Unsupported bound operation.');
    }

    private function createNumericTarget(DatasetBinding $dataset, VariableBinding $target): void
    {
        if (!$this->connection->profile->ddlAtomic()) {
            throw TransformationFailure::at(
                'schema_change_not_atomic',
                '$.operations',
                'Numeric target creation reached the executor on a non-atomic profile; preflight should have rejected the plan.',
            );
        }

        CheckedPdo::exec(
            $this->connection->pdo,
            'ALTER TABLE ' . $dataset->qualifiedTable($this->connection->profile)
            . ' ADD COLUMN ' . $this->quote($target->physicalName)
            . ' ' . $this->connection->profile->numericType() . ' NULL',
            'The numeric target column could not be added.',
        );
        CheckedPdo::execute($this->statement(
            'INSERT INTO variable '
            . '(variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind, declared_string_width) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
        ), [
            $target->variableId,
            $dataset->datasetId,
            $target->sourceOrdinal,
            $target->sourceName,
            $target->physicalName,
            'numeric',
            null,
        ], 'The numeric target catalog row could not be inserted.');
    }

    private function recode(RecodeOperation $operation, BoundOperation $bound, DatasetBinding $dataset): void
    {
        $source = $bound->source;
        if ($source === null) {
            throw new \LogicException('A bound recode requires its source.');
        }
        $sourceSql = $this->quote($source->physicalName);
        $parameters = [];
        $when = [];
        foreach ($operation->rules as $rule) {
            $condition = match (true) {
                $rule->match instanceof ExactMatch => $this->exactMatch($sourceSql, $rule->match, $parameters),
                $rule->match instanceof RangeMatch => $this->rangeMatch($sourceSql, $rule->match, $parameters),
                $rule->match instanceof SystemMissingMatch => '(' . $sourceSql . ' IS NULL)',
                default => throw new \LogicException('Unsupported preflighted recode match.'),
            };
            $when[] = 'WHEN ' . $condition . ' THEN ' . $this->resultSql($rule->result, $sourceSql, $parameters);
        }
        $unmatched = $this->resultSql($operation->unmatched, $sourceSql, $parameters);
        CheckedPdo::execute($this->statement(
            'UPDATE ' . $dataset->qualifiedTable($this->connection->profile)
            . ' SET ' . $this->quote($this->target($bound)->physicalName)
            . ' = CASE ' . implode(' ', $when) . ' ELSE ' . $unmatched . ' END',
        ), $parameters, 'The recode UPDATE could not be executed.');
    }

    /** @param list<float|string|null> $parameters */
    private function exactMatch(string $sourceSql, ExactMatch $match, array &$parameters): string
    {
        $conditions = [];
        foreach ($match->values as $value) {
            if ($value instanceof Binary64Value) {
                $conditions[] = $sourceSql . ' = ' . $this->predicateCompiler->numericLiteral($value, $parameters);
            } else {
                $parameters[] = $this->value($value);
                $conditions[] = $this->connection->profile->exactValueCondition($sourceSql, true);
            }
        }
        return '(' . implode(' OR ', $conditions) . ')';
    }

    /** @param list<float|string|null> $parameters */
    private function rangeMatch(string $sourceSql, RangeMatch $match, array &$parameters): string
    {
        $lower = $this->predicateCompiler->numericLiteral($match->lower, $parameters);
        $upper = $this->predicateCompiler->numericLiteral($match->upper, $parameters);
        return '(' . $sourceSql . ' >= ' . $lower . ' AND ' . $sourceSql . ' <= ' . $upper . ')';
    }

    /** @param list<float|string|null> $parameters */
    private function resultSql(Result $result, string $sourceSql, array &$parameters): string
    {
        if ($result instanceof CopyResult) {
            return $sourceSql;
        }
        if ($result instanceof SystemMissingResult) {
            return 'NULL';
        }
        if ($result instanceof LiteralResult) {
            if ($result->value instanceof Binary64Value) {
                return $this->predicateCompiler->numericLiteral($result->value, $parameters);
            }
            $parameters[] = $this->value($result->value);
            return '?';
        }
        throw new \LogicException('Unsupported preflighted recode result.');
    }

    private function replaceValueLabels(
        ReplaceValueLabelsOperation $operation,
        DatasetBinding $dataset,
        VariableBinding $target,
    ): void {
        $statement = $this->statement(
            'SELECT link.value_label_set_id, value_set.dataset_id FROM variable_value_label_set link '
            . 'JOIN value_label_set value_set ON value_set.value_label_set_id = link.value_label_set_id '
            . 'WHERE link.variable_id = ?',
        );
        CheckedPdo::execute($statement, [$target->variableId], 'The value-label association could not be queried.');
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1 || (isset($rows[0]['dataset_id']) && $rows[0]['dataset_id'] !== $dataset->datasetId)) {
            throw TransformationFailure::at('invalid_catalog', '$.dataset_id', 'Value-label association is malformed.');
        }

        $setId = isset($rows[0]['value_label_set_id']) && is_string($rows[0]['value_label_set_id'])
            ? $rows[0]['value_label_set_id']
            : null;
        if ($setId !== null) {
            $references = $this->statement('SELECT COUNT(*) FROM variable_value_label_set WHERE value_label_set_id = ?');
            CheckedPdo::execute($references, [$setId], 'The value-label reference count could not be queried.');
            if ((int) $references->fetchColumn() > 1) {
                CheckedPdo::execute(
                    $this->statement('DELETE FROM variable_value_label_set WHERE variable_id = ?'),
                    [$target->variableId],
                    'The old shared value-label association could not be removed.',
                );
                $setId = null;
            } else {
                CheckedPdo::execute(
                    $this->statement('DELETE FROM value_label WHERE value_label_set_id = ?'),
                    [$setId],
                    'The old value labels could not be removed.',
                );
            }
        }
        if ($setId === null) {
            $setId = NormativeCatalog::uuid();
            CheckedPdo::execute(
                $this->statement('INSERT INTO value_label_set (value_label_set_id, dataset_id, name) VALUES (?, ?, ?)'),
                [$setId, $dataset->datasetId, null],
                'The value-label set could not be inserted.',
            );
            CheckedPdo::execute(
                $this->statement('INSERT INTO variable_value_label_set (variable_id, value_label_set_id) VALUES (?, ?)'),
                [$target->variableId, $setId],
                'The value-label association could not be inserted.',
            );
        }

        $insert = $this->statement(
            'INSERT INTO value_label '
            . '(value_label_id, value_label_set_id, ordinal, code_kind, numeric_code, string_code, label) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($operation->labels as $index => $label) {
            $numeric = $label->value instanceof Binary64Value;
            CheckedPdo::execute($insert, [
                NormativeCatalog::uuid(),
                $setId,
                $index + 1,
                $numeric ? 'numeric' : 'string',
                $numeric ? $this->value($label->value) : null,
                $numeric ? null : $this->value($label->value),
                $label->label,
            ], 'A replacement value label could not be inserted.');
        }
    }

    private function value(TypedValue $value): string
    {
        return match (true) {
            $value instanceof Binary64Value => $value->decimal(),
            $value instanceof StringValue => $value->value,
            default => throw new \LogicException('Unsupported preflighted typed value.'),
        };
    }

    private function target(BoundOperation $bound): VariableBinding
    {
        if ($bound->target === null) {
            throw new \LogicException('The bound operation requires a target.');
        }
        return $bound->target;
    }

    private function quote(string $identifier): string
    {
        return $this->connection->profile->quoteIdentifier($identifier);
    }

    private function statement(string $sql): PDOStatement
    {
        return CheckedPdo::prepare(
            $this->connection->pdo,
            $sql,
            'Transformation SQL statement could not be prepared.',
        );
    }
}
