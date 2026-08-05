<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

use OpenStatSpec\Core\Binary64;
use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Sql\OperationJournal;
use OpenStatSpec\Transformation\Model\CreateVariableOperation;
use OpenStatSpec\Transformation\Model\DeleteVariableOperation;
use OpenStatSpec\Transformation\Model\Action\AssignValueAction;
use OpenStatSpec\Transformation\Model\Action\CopySourceAction;
use OpenStatSpec\Transformation\Model\Action\SetMissingAction;
use OpenStatSpec\Transformation\Model\RecodeAction;
use OpenStatSpec\Transformation\Model\RecodeOperation;
use OpenStatSpec\Transformation\Model\ScalarValue;
use OpenStatSpec\Transformation\Model\Selector\ElseSelector;
use OpenStatSpec\Transformation\Model\Selector\ExactValueSelector;
use OpenStatSpec\Transformation\Model\Selector\MissingValueSelector;
use OpenStatSpec\Transformation\Model\Selector\NumericRangeSelector;
use OpenStatSpec\Transformation\Model\SetValueLabelsOperation;
use OpenStatSpec\Transformation\Model\SetVariableLabelOperation;
use OpenStatSpec\Transformation\Model\TransformationOperation;
use OpenStatSpec\Transformation\Model\TransformationPlan;
use OpenStatSpec\Transformation\Validation\PlanValidator;
use PDO;
use PDOException;
use PDOStatement;
use SPSS\Sav\Variable;
use Throwable;

/** Executes a canonical plan against its registered wide table, in place. */
final class InPlaceTransformationExecutor
{
    private readonly PlanValidator $validator;

    public function __construct(
        private readonly Connection $connection,
        ?PlanValidator $validator = null,
        private readonly ?DoltEvidenceReader $doltEvidenceReader = null,
    ) {
        $this->validator = $validator ?? new PlanValidator();
    }

    public function execute(TransformationPlan $plan): ExecutionResult
    {
        $this->validator->assertValid($plan);
        $this->connection->assertClaimedSupported();
        if ($this->connection->pdo->inTransaction()) {
            throw new UnsupportedOperation(
                DiagnosticCode::UnsupportedOperation,
                'An in-place transformation cannot start inside a caller-owned active transaction.',
            );
        }
        CatalogOwnership::assertReadyForUse($this->connection->pdo);

        $dataset = $this->resolveDataset($plan->datasetId());
        $variables = $this->resolveVariables($dataset);
        $plannedVariables = $this->preflight($plan, $variables);

        $guard = $this->doltGuard();
        $doltBefore = $guard?->beforeExecution();
        $journal = $this->existingJournal();
        $operationId = $journal?->start(
            'transform',
            $dataset->datasetName,
            null,
            [],
            $this->auditDetails($plan, $dataset, $doltBefore),
        );
        $doltAfter = null;

        try {
            if (!$this->connection->pdo->beginTransaction()) {
                throw new PDOException('The SQL driver did not start the transformation transaction.');
            }
            $created = [];
            foreach ($plan->operations() as $operation) {
                $this->applyOperation($operation, $dataset, $plannedVariables, $created);
            }
            $doltAfter = $doltBefore === null
                ? null
                : $guard->afterExecution($doltBefore);
            if ($journal !== null && $operationId !== null) {
                $journal->succeed($operationId, $dataset->datasetName, []);
            }
            if (!$this->connection->pdo->commit()) {
                throw new PDOException('The SQL driver did not commit the transformation transaction.');
            }
        } catch (Throwable $exception) {
            if ($this->connection->pdo->inTransaction()) {
                $this->connection->pdo->rollBack();
            }
            if ($journal !== null && $operationId !== null) {
                try {
                    $journal->fail($operationId, $dataset->datasetName, $exception);
                } catch (Throwable) {
                    // Preserve the transformation failure; audit is best-effort after rollback.
                }
            }
            throw $exception;
        }

        return new ExecutionResult(
            $dataset->datasetId,
            $plan->hash(),
            count($plan->operations()),
            $operationId,
            $doltBefore,
            $doltAfter,
        );
    }

    private function resolveDataset(string $datasetId): DatasetBinding
    {
        $statement = $this->statement(
            'SELECT dataset_id, dataset_name, physical_table_schema, physical_table_name FROM dataset WHERE dataset_id = ?',
        );
        $statement->execute([$datasetId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw $this->invalidCatalog('The transformation dataset_id resolves to exactly one normative dataset row.');
        }
        $row = $rows[0];
        $table = $row['physical_table_name'] ?? null;
        $schema = $row['physical_table_schema'] ?? null;
        $name = $row['dataset_name'] ?? null;
        if (($row['dataset_id'] ?? null) !== $datasetId
            || !is_string($table)
            || $table === ''
            || ($schema !== null && (!is_string($schema) || $schema === ''))
            || ($name !== null && !is_string($name))
        ) {
            throw $this->invalidCatalog('The normative dataset row has malformed physical table metadata.');
        }

        return new DatasetBinding($datasetId, $name, $schema, $table);
    }

    /** @return array<string, VariableBinding> */
    private function resolveVariables(DatasetBinding $dataset): array
    {
        $statement = $this->statement(
            'SELECT variable_id, source_name, physical_name, storage_kind, source_ordinal, declared_string_width '
            . 'FROM variable WHERE dataset_id = ? ORDER BY source_ordinal',
        );
        $statement->execute([$dataset->datasetId]);
        $variables = [];
        $physicalNames = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = $row['variable_id'] ?? null;
            $source = $row['source_name'] ?? null;
            $physical = $row['physical_name'] ?? null;
            $kind = $row['storage_kind'] ?? null;
            $ordinal = filter_var($row['source_ordinal'] ?? null, FILTER_VALIDATE_INT);
            $declaredStringWidth = $row['declared_string_width'] === null
                ? null
                : filter_var($row['declared_string_width'], FILTER_VALIDATE_INT);
            if (!is_string($id) || $id === ''
                || !is_string($source) || $source === ''
                || !is_string($physical) || $physical === ''
                || !in_array($kind, ['numeric', 'string'], true)
                || !is_int($ordinal) || $ordinal < 1
                || ($kind === 'string' && (!is_int($declaredStringWidth) || $declaredStringWidth < 1))
                || isset($variables[$source]) || isset($physicalNames[$physical])
            ) {
                throw $this->invalidCatalog('The normative variable mapping is malformed or ambiguous.');
            }
            $variables[$source] = new VariableBinding(
                $id,
                $source,
                $physical,
                $kind,
                $ordinal,
                is_int($declaredStringWidth) ? $declaredStringWidth : null,
            );
            $physicalNames[$physical] = true;
        }
        if ($variables === []) {
            throw $this->invalidCatalog('The registered dataset has no normative variable mappings.');
        }

        $columns = implode(', ', array_map(
            fn(VariableBinding $variable): string => $this->quote($variable->physicalName),
            array_values($variables),
        ));
        try {
            $this->connection->pdo->query(
                'SELECT ' . $columns . ' FROM ' . $this->qualifiedTable($dataset) . ' WHERE 1 = 0',
            );
        } catch (PDOException $exception) {
            throw $this->invalidCatalog('The catalogued physical wide table or variable mapping is not readable: ' . $exception->getMessage());
        }

        return $variables;
    }

    /**
     * @param array<string, VariableBinding> $variables
     * @return array<string, VariableBinding>
     */
    private function preflight(TransformationPlan $plan, array $variables): array
    {
        $used = [];
        $nextOrdinal = 1;
        foreach ($variables as $variable) {
            $used[$variable->physicalName] = true;
            $nextOrdinal = max($nextOrdinal, $variable->sourceOrdinal + 1);
        }

        $active = array_fill_keys(array_keys($variables), true);
        foreach ($plan->operations() as $operation) {
            if ($operation instanceof CreateVariableOperation) {
                if (isset($variables[$operation->targetVariable()])) {
                    throw $this->invalidCatalog(sprintf(
                        'Create variable "%s" collides with an existing catalog variable.',
                        $operation->targetVariable(),
                    ));
                }
                $this->assertCanAlterSchema(count($active), true);
                $physical = $this->connection->profile->physicalIdentifier($operation->targetVariable(), $used);
                $variables[$operation->targetVariable()] = new VariableBinding(
                    NormativeCatalog::uuid(),
                    $operation->targetVariable(),
                    $physical,
                    $operation->storageKind(),
                    $nextOrdinal++,
                    $operation->declaredStringWidth(),
                    false,
                );
                $used[$physical] = true;
                $active[$operation->targetVariable()] = true;
                continue;
            }
            if ($operation instanceof DeleteVariableOperation) {
                $variableName = $operation->targetVariable();
                if (!isset($active[$variableName])) {
                    throw $this->invalidCatalog(sprintf(
                        'Delete variable "%s" is not registered for dataset_id %s.',
                        $variableName,
                        $plan->datasetId(),
                    ));
                }
                if (count($active) === 1) {
                    throw $this->invalidCatalog('A transformation cannot delete the final dataset variable.');
                }
                $this->assertCanAlterSchema(count($active), false);
                unset($active[$variableName]);
                continue;
            }
            if ($operation instanceof RecodeOperation) {
                $source = $variables[$operation->sourceVariable()] ?? null;
                if ($source === null || !isset($active[$operation->sourceVariable()])) {
                    throw $this->invalidCatalog(sprintf(
                        'Recode source variable "%s" is not registered for dataset_id %s.',
                        $operation->sourceVariable(),
                        $plan->datasetId(),
                    ));
                }
                $target = $variables[$operation->targetVariable()] ?? null;
                if ($target !== null && !isset($active[$operation->targetVariable()])) {
                    throw $this->invalidCatalog(sprintf(
                        'Recode target variable "%s" is no longer active.',
                        $operation->targetVariable(),
                    ));
                }
                if ($target === null) {
                    $this->assertCanCreateTarget($source, count($active));
                    $physical = $this->connection->profile->physicalIdentifier($operation->targetVariable(), $used);
                    $target = new VariableBinding(
                        NormativeCatalog::uuid(),
                        $operation->targetVariable(),
                        $physical,
                        $source->storageKind,
                        $nextOrdinal++,
                        null,
                        false,
                    );
                    $variables[$operation->targetVariable()] = $target;
                    $used[$physical] = true;
                    $active[$operation->targetVariable()] = true;
                }
                $this->assertRecodeKinds($operation, $source, $target);
                continue;
            }

            $target = $variables[$operation->targetVariable()] ?? null;
            if ($target === null || !isset($active[$operation->targetVariable()])) {
                throw $this->invalidCatalog(sprintf(
                    'Metadata target variable "%s" is not registered for dataset_id %s.',
                    $operation->targetVariable(),
                    $plan->datasetId(),
                ));
            }
            if ($operation instanceof SetValueLabelsOperation) {
                foreach ($operation->labels() as $label) {
                    $this->assertScalarKind($label->value(), $target, 'value label');
                }
            }
        }

        return $variables;
    }

    private function assertCanAlterSchema(int $registeredVariableCount, bool $creation): void
    {
        if (!in_array($this->connection->profileName, ['sqlite', 'postgresql'], true)
            || !$this->connection->profile->ddlAtomic()
        ) {
            throw new UnsupportedOperation(
                DiagnosticCode::TargetCapabilityExceeded,
                sprintf(
                    '%s cannot atomically alter an existing wide table for an in-place variable transformation.',
                    $this->connection->profileName,
                ),
            );
        }
        if (!$creation) {
            if ($this->connection->profileName === 'sqlite'
                && version_compare($this->connection->serverVersion, '3.35.0', '<')
            ) {
                throw new UnsupportedOperation(
                    DiagnosticCode::TargetCapabilityExceeded,
                    'SQLite versions below 3.35.0 do not support DROP COLUMN for DELETE VARIABLES.',
                );
            }

            return;
        }

        $maximum = $this->connection->profile->effectiveMaximumSourceVariables($this->connection->pdo);
        if ($registeredVariableCount >= $maximum) {
            throw new UnsupportedOperation(
                DiagnosticCode::TargetCapabilityExceeded,
                sprintf(
                    '%s supports at most %d source variables in one OpenStatSpec wide table.',
                    $this->connection->profileName,
                    $maximum,
                ),
            );
        }
    }
    private function assertCanCreateTarget(VariableBinding $source, int $registeredVariableCount): void
    {
        if ($source->storageKind === 'string') {
            throw new UnsupportedOperation(
                DiagnosticCode::TargetCapabilityExceeded,
                'A new string target requires an explicit declared_string_width; register the target variable first.',
            );
        }
        if (!in_array($this->connection->profileName, ['sqlite', 'postgresql'], true)
            || !$this->connection->profile->ddlAtomic()
        ) {
            throw new UnsupportedOperation(
                DiagnosticCode::TargetCapabilityExceeded,
                sprintf(
                    '%s cannot atomically add a new INTO target to an existing wide table; register the target variable first.',
                    $this->connection->profileName,
                ),
            );
        }

        $maximum = $this->connection->profile->effectiveMaximumSourceVariables($this->connection->pdo);
        if ($registeredVariableCount >= $maximum) {
            throw new UnsupportedOperation(
                DiagnosticCode::TargetCapabilityExceeded,
                sprintf(
                    '%s supports at most %d source variables in one OpenStatSpec wide table.',
                    $this->connection->profileName,
                    $maximum,
                ),
            );
        }
    }

    private function assertRecodeKinds(
        RecodeOperation $operation,
        VariableBinding $source,
        VariableBinding $target,
    ): void {
        foreach ($operation->rules() as $rule) {
            $selector = $rule->selector();
            if ($selector instanceof ExactValueSelector) {
                $this->assertScalarKind($selector->value(), $source, 'exact recode selector');
            } elseif ($selector instanceof NumericRangeSelector && $source->storageKind !== 'numeric') {
                throw $this->invalidCatalog('A numeric range selector cannot read a string variable.');
            }

            $action = $rule->action();
            if ($action instanceof AssignValueAction) {
                $this->assertScalarKind($action->value(), $target, 'recode assignment');
            } elseif ($action instanceof CopySourceAction && $source->storageKind !== $target->storageKind) {
                throw $this->invalidCatalog('Copy-source recoding requires source and target variables with the same storage kind.');
            } elseif ($action instanceof CopySourceAction
                && $source->storageKind === 'string'
                && $this->stringWidth($source) > $this->stringWidth($target)
            ) {
                throw $this->invalidCatalog(sprintf(
                    'Copy-source recoding cannot copy string width %d into variable "%s" width %d.',
                    $this->stringWidth($source),
                    $target->sourceName,
                    $this->stringWidth($target),
                ));
            } elseif ($action instanceof SetMissingAction && $target->storageKind === 'string') {
                throw $this->invalidCatalog(sprintf(
                    'System-missing recoding is not representable for string variable "%s".',
                    $target->sourceName,
                ));
            }
        }
    }

    private function assertScalarKind(ScalarValue $value, VariableBinding $variable, string $context): void
    {
        $kind = $value->type() === 'number' ? 'numeric' : 'string';
        if ($kind !== $variable->storageKind) {
            throw $this->invalidCatalog(sprintf(
                'The %s type does not match variable "%s" storage kind %s.',
                $context,
                $variable->sourceName,
                $variable->storageKind,
            ));
        }
        if ($kind === 'string' && strlen($value->stringValue()) > $this->stringWidth($variable)) {
            throw $this->invalidCatalog(sprintf(
                'The %s exceeds variable "%s" declared string width %d bytes.',
                $context,
                $variable->sourceName,
                $this->stringWidth($variable),
            ));
        }
    }

    private function stringWidth(VariableBinding $variable): int
    {
        if ($variable->storageKind !== 'string'
            || $variable->declaredStringWidth === null
            || $variable->declaredStringWidth < 1
        ) {
            throw $this->invalidCatalog(sprintf(
                'String variable "%s" requires a positive declared_string_width.',
                $variable->sourceName,
            ));
        }

        return $variable->declaredStringWidth;
    }

    /**
     * @param array<string, VariableBinding> $variables
     * @param array<string, true>            $created
     */
    private function applyOperation(
        TransformationOperation $operation,
        DatasetBinding $dataset,
        array $variables,
        array &$created,
    ): void {
        if ($operation instanceof CreateVariableOperation) {
            $target = $variables[$operation->targetVariable()];
            $this->ensureTargetExists($dataset, $target, $created);
            return;
        }
        if ($operation instanceof DeleteVariableOperation) {
            $target = $variables[$operation->targetVariable()];
            $this->deleteVariable($dataset, $target);
            return;
        }
        $target = $variables[$operation->targetVariable()];
        if ($operation instanceof RecodeOperation) {
            $this->ensureTargetExists($dataset, $target, $created);
            $this->applyRecode($operation, $dataset, $variables[$operation->sourceVariable()], $target);
        } elseif ($operation instanceof SetVariableLabelOperation) {
            $this->applyVariableLabel($operation, $dataset, $target);
        } elseif ($operation instanceof SetValueLabelsOperation) {
            $this->applyValueLabels($operation, $dataset, $target);
        }
    }

    /** @param array<string, true> $created */
    private function ensureTargetExists(DatasetBinding $dataset, VariableBinding $target, array &$created): void
    {
        if ($target->persisted || isset($created[$target->sourceName])) {
            return;
        }
        $type = $target->storageKind === 'numeric'
            ? $this->connection->profile->numericType()
            : $this->connection->profile->textType();
        $columnDefinition = $target->storageKind === 'string'
            ? $type . " NOT NULL DEFAULT ''"
            : $type . ' NULL';
        $this->connection->pdo->exec(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            $this->qualifiedTable($dataset),
            $this->quote($target->physicalName),
            $columnDefinition,
        ));
        $formatFamily = $target->storageKind === 'string' ? Variable::FORMAT_TYPE_A : 5;
        $formatWidth = $target->storageKind === 'string'
            ? min(255, $this->stringWidth($target))
            : 8;
        $this->statement(
            'INSERT INTO variable '
            . '(variable_id, dataset_id, source_ordinal, source_name, physical_name, storage_kind, declared_string_width, '
            . 'print_format_family, print_format_width, print_format_decimals, '
            . 'write_format_family, write_format_width, write_format_decimals) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $target->variableId,
            $dataset->datasetId,
            $target->sourceOrdinal,
            $target->sourceName,
            $target->physicalName,
            $target->storageKind,
            $target->declaredStringWidth,
            $formatFamily,
            $formatWidth,
            0,
            $formatFamily,
            $formatWidth,
            0,
        ]);
        $created[$target->sourceName] = true;
    }

    private function deleteVariable(DatasetBinding $dataset, VariableBinding $target): void
    {
        $this->connection->pdo->exec(sprintf(
            'ALTER TABLE %s DROP COLUMN %s',
            $this->qualifiedTable($dataset),
            $this->quote($target->physicalName),
        ));
        $association = $this->statement(
            'SELECT vvls.value_label_set_id, vls.dataset_id FROM variable_value_label_set vvls '
            . 'JOIN value_label_set vls ON vls.value_label_set_id = vvls.value_label_set_id '
            . 'WHERE vvls.variable_id = ?',
        );
        $association->execute([$target->variableId]);
        $rows = $association->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1 || (isset($rows[0]['dataset_id']) && $rows[0]['dataset_id'] !== $dataset->datasetId)) {
            throw $this->invalidCatalog('The variable value-label association is malformed or crosses dataset identity.');
        }
        $setId = isset($rows[0]['value_label_set_id']) && is_string($rows[0]['value_label_set_id'])
            ? $rows[0]['value_label_set_id']
            : null;

        foreach ([
            'DELETE FROM dataset_weight_variable WHERE variable_id = ?',
            'DELETE FROM variable_set_member WHERE variable_id = ?',
            'DELETE FROM multiple_response_member WHERE variable_id = ?',
            'DELETE FROM variable_attribute WHERE variable_id = ?',
            'DELETE FROM missing_rule WHERE variable_id = ?',
            'DELETE FROM variable_value_label_set WHERE variable_id = ?',
        ] as $sql) {
            $this->statement($sql)->execute([$target->variableId]);
        }
        $this->statement('DELETE FROM variable WHERE variable_id = ? AND dataset_id = ?')->execute([
            $target->variableId,
            $dataset->datasetId,
        ]);

        if ($setId === null) {
            return;
        }
        $references = $this->statement(
            'SELECT COUNT(*) FROM variable_value_label_set WHERE value_label_set_id = ?',
        );
        $references->execute([$setId]);
        if ((int) $references->fetchColumn() === 0) {
            $this->statement('DELETE FROM value_label WHERE value_label_set_id = ?')->execute([$setId]);
            $this->statement('DELETE FROM value_label_set WHERE value_label_set_id = ? AND dataset_id = ?')->execute([
                $setId,
                $dataset->datasetId,
            ]);
        }
    }
    private function applyRecode(
        RecodeOperation $operation,
        DatasetBinding $dataset,
        VariableBinding $source,
        VariableBinding $target,
    ): void {
        $sourceSql = $this->quote($source->physicalName);
        $when = [];
        $parameters = [];
        $else = null;
        foreach ($operation->rules() as $rule) {
            $selector = $rule->selector();
            if ($selector instanceof ElseSelector) {
                $else = $this->actionSql($rule->action(), $sourceSql, $parameters);
                continue;
            }
            $condition = match (true) {
                $selector instanceof ExactValueSelector => $this->exactCondition($sourceSql, $selector, $parameters),
                $selector instanceof NumericRangeSelector => $this->rangeCondition($sourceSql, $selector, $parameters),
                $selector instanceof MissingValueSelector => $sourceSql . ' IS NULL',
                default => throw $this->invalidCatalog('The recode selector is not executable.'),
            };
            $actionSql = $this->actionSql($rule->action(), $sourceSql, $parameters);
            $when[] = 'WHEN ' . $condition . ' THEN ' . $actionSql;
        }
        if ($else === null) {
            throw $this->invalidCatalog('A recode requires one explicit final else action.');
        }
        if ($when === []) {
            $this->statement(sprintf(
                'UPDATE %s SET %s = %s',
                $this->qualifiedTable($dataset),
                $this->quote($target->physicalName),
                $else,
            ))->execute($parameters);

            return;
        }
        $sql = sprintf(
            'UPDATE %s SET %s = CASE %s ELSE %s END',
            $this->qualifiedTable($dataset),
            $this->quote($target->physicalName),
            implode(' ', $when),
            $else,
        );
        $this->statement($sql)->execute($parameters);
    }

    /** @param list<float|string> $parameters */
    private function exactCondition(string $sourceSql, ExactValueSelector $selector, array &$parameters): string
    {
        $parameters[] = $this->boundScalar($selector->value());

        return $this->connection->profile->exactValueCondition(
            $sourceSql,
            $selector->value()->type() === 'string',
        );
    }

    /** @param list<float|string> $parameters */
    private function rangeCondition(string $sourceSql, NumericRangeSelector $selector, array &$parameters): string
    {
        $parts = [];
        if ($selector->lower() !== null) {
            $parts[] = $sourceSql . ' >= ?';
            $parameters[] = Binary64::encode($selector->lower());
        }
        if ($selector->upper() !== null) {
            $parts[] = $sourceSql . ' <= ?';
            $parameters[] = Binary64::encode($selector->upper());
        }

        return implode(' AND ', $parts);
    }

    /** @param list<float|string> $parameters */
    private function actionSql(RecodeAction $action, string $sourceSql, array &$parameters): string
    {
        if ($action instanceof AssignValueAction) {
            $parameters[] = $this->boundScalar($action->value());

            return '?';
        }
        if ($action instanceof SetMissingAction) {
            return 'NULL';
        }
        if ($action instanceof CopySourceAction) {
            return $sourceSql;
        }

        throw $this->invalidCatalog('The recode action is not executable.');
    }

    private function applyVariableLabel(
        SetVariableLabelOperation $operation,
        DatasetBinding $dataset,
        VariableBinding $target,
    ): void {
        $this->statement('UPDATE variable SET variable_label = ? WHERE variable_id = ? AND dataset_id = ?')->execute([
            $operation->label(),
            $target->variableId,
            $dataset->datasetId,
        ]);
    }

    private function applyValueLabels(
        SetValueLabelsOperation $operation,
        DatasetBinding $dataset,
        VariableBinding $target,
    ): void {
        $statement = $this->statement(
            'SELECT vvls.value_label_set_id, vls.dataset_id FROM variable_value_label_set vvls '
            . 'JOIN value_label_set vls ON vls.value_label_set_id = vvls.value_label_set_id '
            . 'WHERE vvls.variable_id = ?',
        );
        $statement->execute([$target->variableId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1 || (isset($rows[0]['dataset_id']) && $rows[0]['dataset_id'] !== $dataset->datasetId)) {
            throw $this->invalidCatalog('The variable value-label association is malformed or crosses dataset identity.');
        }

        $setId = isset($rows[0]['value_label_set_id']) && is_string($rows[0]['value_label_set_id'])
            ? $rows[0]['value_label_set_id']
            : null;
        if ($setId !== null) {
            $references = $this->statement(
                'SELECT COUNT(*) FROM variable_value_label_set WHERE value_label_set_id = ?',
            );
            $references->execute([$setId]);
            $referenceCount = (int) $references->fetchColumn();
            if ($referenceCount > 1) {
                $this->statement('DELETE FROM variable_value_label_set WHERE variable_id = ?')->execute([$target->variableId]);
                $setId = null;
            } else {
                $this->statement('DELETE FROM value_label WHERE value_label_set_id = ?')->execute([$setId]);
            }
        }

        if ($operation->labels() === []) {
            if ($setId !== null) {
                $this->statement('DELETE FROM variable_value_label_set WHERE variable_id = ?')->execute([$target->variableId]);
                $this->statement('DELETE FROM value_label_set WHERE value_label_set_id = ?')->execute([$setId]);
            }

            return;
        }

        if ($setId === null) {
            $setId = NormativeCatalog::uuid();
            $this->statement('INSERT INTO value_label_set (value_label_set_id, dataset_id, name) VALUES (?, ?, ?)')->execute([
                $setId,
                $dataset->datasetId,
                null,
            ]);
            $this->statement(
                'INSERT INTO variable_value_label_set (variable_id, value_label_set_id) VALUES (?, ?)',
            )->execute([$target->variableId, $setId]);
        }

        $insert = $this->statement(
            'INSERT INTO value_label '
            . '(value_label_id, value_label_set_id, ordinal, code_kind, numeric_code, string_code, label) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($operation->labels() as $index => $label) {
            $value = $label->value();
            $insert->execute([
                NormativeCatalog::uuid(),
                $setId,
                $index + 1,
                $value->type() === 'number' ? 'numeric' : 'string',
                $value->type() === 'number' ? Binary64::encode($value->numberValue()) : null,
                $value->type() === 'string' ? $value->stringValue() : null,
                $label->label(),
            ]);
        }
    }

    private function boundScalar(ScalarValue $value): string
    {
        return $value->type() === 'number'
            ? Binary64::encode($value->numberValue())
            : $value->stringValue();
    }

    private function qualifiedTable(DatasetBinding $dataset): string
    {
        $table = $this->quote($dataset->table);

        return $dataset->schema === null ? $table : $this->quote($dataset->schema) . '.' . $table;
    }

    private function quote(string $identifier): string
    {
        return $this->connection->profile->quoteIdentifier($identifier);
    }

    private function statement(string $sql): PDOStatement
    {
        $statement = $this->connection->pdo->prepare($sql);
        if ($statement === false) {
            throw new PDOException('The transformation SQL statement could not be prepared.');
        }

        return $statement;
    }

    private function doltGuard(): ?DoltGuard
    {
        if ($this->connection->profileName !== 'dolt') {
            return null;
        }

        return new DoltGuard($this->doltEvidenceReader ?? new PdoDoltEvidenceReader($this->connection->pdo));
    }

    private function existingJournal(): ?OperationJournal
    {
        try {
            $this->connection->pdo->query('SELECT operation_id FROM operation_catalog WHERE 1 = 0');
            $this->connection->pdo->query('SELECT operation_id FROM fidelity_event_catalog WHERE 1 = 0');
            $this->connection->pdo->query('SELECT operation_id FROM operation WHERE 1 = 0');
        } catch (PDOException) {
            return null;
        }

        return new OperationJournal($this->connection->pdo);
    }

    /** @return array<string, string|null> */
    private function auditDetails(
        TransformationPlan $plan,
        DatasetBinding $dataset,
        ?DoltEvidence $doltBefore,
    ): array {
        return [
            'plan_hash' => $plan->hash(),
            'dataset_id' => $dataset->datasetId,
            'mode' => 'in_place',
            'dolt_branch_before' => $doltBefore?->branch(),
            'dolt_head_before' => $doltBefore?->head(),
        ];
    }

    private function invalidCatalog(string $message): UnsupportedOperation
    {
        return new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, $message);
    }
}
