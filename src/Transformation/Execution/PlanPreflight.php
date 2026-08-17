<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\NormativeCatalog;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Plan\Expression\BooleanPredicate;
use OpenStatSpec\Transformation\Plan\Expression\Comparison;
use OpenStatSpec\Transformation\Plan\Expression\LiteralOperand;
use OpenStatSpec\Transformation\Plan\Expression\Operand;
use OpenStatSpec\Transformation\Plan\Expression\Predicate;
use OpenStatSpec\Transformation\Plan\Expression\VariableOperand;
use OpenStatSpec\Transformation\Plan\Operation;
use OpenStatSpec\Transformation\Plan\Operation\AssignOperation;
use OpenStatSpec\Transformation\Plan\Operation\ConditionalAssignOperation;
use OpenStatSpec\Transformation\Plan\Operation\ExecuteOperation;
use OpenStatSpec\Transformation\Plan\Operation\RecodeOperation;
use OpenStatSpec\Transformation\Plan\Operation\ReplaceValueLabelsOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetFormatOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetMeasurementLevelOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetVariableLabelOperation;
use OpenStatSpec\Transformation\Plan\PlanCodec;
use OpenStatSpec\Transformation\Plan\Recode\CopyResult;
use OpenStatSpec\Transformation\Plan\Recode\ExactMatch;
use OpenStatSpec\Transformation\Plan\Recode\LiteralResult;
use OpenStatSpec\Transformation\Plan\Recode\RangeMatch;
use OpenStatSpec\Transformation\Plan\Recode\RecodeRule;
use OpenStatSpec\Transformation\Plan\Recode\Result;
use OpenStatSpec\Transformation\Plan\Recode\SystemMissingMatch;
use OpenStatSpec\Transformation\Plan\Recode\SystemMissingResult;
use OpenStatSpec\Transformation\Plan\TargetMode;
use OpenStatSpec\Transformation\Plan\Value\Binary64Value;
use OpenStatSpec\Transformation\Plan\Value\StringValue;
use OpenStatSpec\Transformation\Plan\Value\TypedValue;
use PDO;
use PDOException;
use PDOStatement;

/** Resolves and validates a complete apply without performing any mutation. */
final readonly class PlanPreflight
{
    private PlanCodec $codec;

    public function __construct(private Connection $connection, ?PlanCodec $codec = null)
    {
        $this->codec = $codec ?? new PlanCodec();
    }

    public function isFor(PDO $pdo): bool
    {
        return $this->connection->pdo === $pdo;
    }

    public function bind(InPlaceApplyRequest $request): BoundPlan
    {
        // Re-decode the public model so directly constructed objects receive the
        // same complete structural and semantic validation as decoded plans.
        $this->codec->fromArray($request->plan->canonicalArray());
        $this->connection->assertClaimedSupported();
        CatalogOwnership::assertReadyForUseReadOnly($this->connection->pdo);
        $this->assertAuditReady();

        $dataset = $this->resolveDataset($request->datasetId);
        $this->assertMySqlFamilyTransactionalEngines($dataset);
        $this->assertExclusivePhysicalTableBinding($dataset);
        $variables = $this->resolveVariables($dataset);
        $physicalColumns = $this->physicalColumns($dataset);
        $this->assertPhysicalBindings($dataset, $variables, $physicalColumns);

        $usedPhysical = array_fill_keys($physicalColumns, true);
        $nextOrdinal = 1;
        foreach ($variables as $variable) {
            $nextOrdinal = max($nextOrdinal, $variable->sourceOrdinal + 1);
        }
        $postgresqlSlots = $this->connection->profileName === 'postgresql'
            ? $this->postgresqlPhysicalColumnSlots($dataset)
            : null;
        $bound = [];

        foreach ($request->plan->operations as $index => $operation) {
            $path = '$.operations[' . $index . ']';
            if ($this->createsTarget($operation) && !$this->connection->profile->ddlAtomic()) {
                throw TransformationFailure::at(
                    'schema_change_not_atomic',
                    $path . '.target_mode',
                    $this->connection->profileName . ' requires a pre-provisioned target.',
                );
            }
            $schemaBefore = new BoundSchema($variables);
            [$source, $target] = $this->bindOperation(
                $operation,
                $schemaBefore,
                $path,
                $variables,
                $usedPhysical,
                $nextOrdinal,
                $postgresqlSlots,
            );
            $bound[] = new BoundOperation($operation, $schemaBefore, $source, $target);
            if ($target !== null && !$target->persisted) {
                $variables[$target->sourceName] = new VariableBinding(
                    $target->variableId,
                    $target->sourceName,
                    $target->physicalName,
                    $target->storageKind,
                    $target->sourceOrdinal,
                    $target->declaredStringWidth,
                );
                $usedPhysical[$target->physicalName] = true;
                ++$nextOrdinal;
                if ($postgresqlSlots !== null) {
                    ++$postgresqlSlots;
                }
            }
        }

        return new BoundPlan($request->plan, $dataset, $bound, new BoundSchema($variables));
    }

    /**
     * @param array<string, VariableBinding> $variables
     * @param array<string, true>            $usedPhysical
     * @return array{VariableBinding|null, VariableBinding|null}
     */
    private function bindOperation(
        Operation $operation,
        BoundSchema $schema,
        string $path,
        array $variables,
        array $usedPhysical,
        int $nextOrdinal,
        ?int $postgresqlSlots,
    ): array {
        if ($operation instanceof AssignOperation) {
            $this->assertNumericOperand($operation->value, $schema, $path . '.value');
            $target = $this->bindNumericTarget(
                $operation->target,
                $operation->targetMode,
                $path . '.target',
                $variables,
                $usedPhysical,
                $nextOrdinal,
                $postgresqlSlots,
            );
            return [null, $target];
        }

        if ($operation instanceof ConditionalAssignOperation) {
            $target = $variables[$operation->target] ?? null;
            if ($target === null) {
                throw TransformationFailure::at(
                    'conditional_target_missing',
                    $path . '.target',
                    'Conditional assignment requires an existing target.',
                );
            }
            $this->assertNumericVariable($target, $path . '.target', 'Conditional assignment target must be numeric.');
            $this->assertPredicate($operation->condition, $schema, $path . '.condition');
            $this->assertNumericOperand($operation->value, $schema, $path . '.value');
            return [null, $target];
        }

        if ($operation instanceof RecodeOperation) {
            $source = $variables[$operation->source] ?? null;
            if ($source === null) {
                throw TransformationFailure::at('unknown_variable', $path . '.source', 'Recode source is not registered.');
            }
            $target = $this->bindRecodeTarget(
                $operation,
                $source,
                $path,
                $variables,
                $usedPhysical,
                $nextOrdinal,
                $postgresqlSlots,
            );
            $this->assertRecode($operation, $source, $target, $path);
            return [$source, $target];
        }

        if ($operation instanceof SetVariableLabelOperation) {
            return [null, $schema->variable($operation->variable, $path . '.variable')];
        }

        if ($operation instanceof ReplaceValueLabelsOperation) {
            $target = $schema->variable($operation->variable, $path . '.variable');
            $this->assertValueLabelAssociations($target, $path . '.variable');
            foreach ($operation->labels as $index => $label) {
                $this->assertValueKind($label->value, $target, $path . '.labels[' . $index . '].value');
            }
            return [null, $target];
        }

        if ($operation instanceof SetFormatOperation) {
            $target = $schema->variable($operation->variable, $path . '.variable');
            $this->assertNumericVariable($target, $path . '.variable', 'F format requires a numeric variable.');
            if ($operation->family !== 'F'
                || $operation->width < 1
                || $operation->width > 40
                || $operation->decimals < 0
                || $operation->decimals > 16
                || ($operation->decimals > 0 && $operation->width < $operation->decimals + 2)
            ) {
                throw TransformationFailure::at('invalid_format', $path, 'Invalid SPSS F format.');
            }
            return [null, $target];
        }

        if ($operation instanceof SetMeasurementLevelOperation) {
            return [null, $schema->variable($operation->variable, $path . '.variable')];
        }

        if ($operation instanceof ExecuteOperation) {
            return [null, null];
        }

        throw TransformationFailure::at('plan_schema_invalid', $path, 'Unsupported official operation.');
    }

    /**
     * @param array<string, VariableBinding> $variables
     * @param array<string, true>            $usedPhysical
     */
    private function bindNumericTarget(
        string $name,
        TargetMode $mode,
        string $path,
        array $variables,
        array $usedPhysical,
        int $nextOrdinal,
        ?int $postgresqlSlots,
    ): VariableBinding {
        $existing = $variables[$name] ?? null;
        if ($mode === TargetMode::Replace) {
            if ($existing === null) {
                throw TransformationFailure::at('unknown_variable', $path, 'Replace target is not registered.');
            }
            $this->assertNumericVariable($existing, $path, 'Assignment target must be numeric.');
            return $existing;
        }
        if ($existing !== null) {
            throw TransformationFailure::at('target_already_exists', $path, 'Create target is already registered.');
        }

        $this->assertCreateCapability(count($variables), $postgresqlSlots);
        $physical = $this->connection->profile->physicalIdentifier($name, $usedPhysical);
        return new VariableBinding(NormativeCatalog::uuid(), $name, $physical, 'numeric', $nextOrdinal, null, false);
    }

    /**
     * @param array<string, VariableBinding> $variables
     * @param array<string, true>            $usedPhysical
     */
    private function bindRecodeTarget(
        RecodeOperation $operation,
        VariableBinding $source,
        string $path,
        array $variables,
        array $usedPhysical,
        int $nextOrdinal,
        ?int $postgresqlSlots,
    ): VariableBinding {
        if (str_starts_with($operation->target, '__')) {
            throw TransformationFailure::at('reserved_target_name', $path . '.target', 'Target name is reserved.');
        }
        $existing = $variables[$operation->target] ?? null;
        if ($operation->targetMode === TargetMode::Replace) {
            if ($existing === null) {
                throw TransformationFailure::at('unknown_variable', $path . '.target', 'Replace target is not registered.');
            }
            if ($existing->variableId !== $source->variableId) {
                throw TransformationFailure::at(
                    'type_mismatch',
                    $path . '.target',
                    'Recode replace requires source and target to identify the same variable.',
                );
            }
            return $existing;
        }
        if ($existing !== null) {
            throw TransformationFailure::at('target_already_exists', $path . '.target', 'Create target is already registered.');
        }

        $resultKind = $this->recodeResultKind($operation, $source, $path);
        if ($resultKind === 'string') {
            throw TransformationFailure::at(
                'string_target_requires_declaration',
                $path . '.target',
                'A new string target requires separate explicit provisioning.',
            );
        }
        $this->assertCreateCapability(count($variables), $postgresqlSlots);
        $physical = $this->connection->profile->physicalIdentifier($operation->target, $usedPhysical);
        return new VariableBinding(NormativeCatalog::uuid(), $operation->target, $physical, 'numeric', $nextOrdinal, null, false);
    }

    private function assertRecode(
        RecodeOperation $operation,
        VariableBinding $source,
        VariableBinding $target,
        string $path,
    ): void {
        foreach ($operation->rules as $index => $rule) {
            $match = $rule->match;
            if ($match instanceof ExactMatch) {
                foreach ($match->values as $valueIndex => $value) {
                    $this->assertValueKind($value, $source, $path . '.rules[' . $index . '].match.values[' . $valueIndex . ']');
                }
            } elseif ($match instanceof RangeMatch) {
                $this->assertNumericVariable($source, $path . '.rules[' . $index . '].match', 'Numeric range requires a numeric source.');
            } elseif ($match instanceof SystemMissingMatch && !$source->isNumeric()) {
                throw TransformationFailure::at('system_missing_for_string', $path . '.rules[' . $index . '].match', 'String variables have no system-missing state.');
            }
            if ($rule->result instanceof LiteralResult) {
                $this->assertValueKind($rule->result->value, $target, $path . '.rules[' . $index . '].result.value');
            }
        }
        if ($operation->unmatched instanceof LiteralResult) {
            $this->assertValueKind($operation->unmatched->value, $target, $path . '.unmatched.value');
        }

        $kind = $this->recodeResultKind($operation, $source, $path);
        if ($kind !== $target->storageKind) {
            throw TransformationFailure::at('type_mismatch', $path . '.target', 'Recode result type does not match the target storage kind.');
        }
    }

    private function recodeResultKind(RecodeOperation $operation, VariableBinding $source, string $path): string
    {
        $results = array_map(static fn(RecodeRule $rule): Result => $rule->result, $operation->rules);
        $results[] = $operation->unmatched;
        $kinds = [];
        foreach ($results as $index => $result) {
            $kind = match (true) {
                $result instanceof CopyResult => $source->storageKind,
                $result instanceof SystemMissingResult => 'numeric',
                $result instanceof LiteralResult => $this->valueKind($result->value),
                default => throw TransformationFailure::at('plan_schema_invalid', $path, 'Unsupported recode result.'),
            };
            if ($result instanceof SystemMissingResult && !$source->isNumeric() && $operation->targetMode === TargetMode::Replace) {
                throw TransformationFailure::at('system_missing_for_string', $path . '.results[' . $index . ']', 'String variables have no system-missing result.');
            }
            $kinds[$kind] = true;
        }
        if (count($kinds) !== 1) {
            throw TransformationFailure::at('mixed_result_types', $path, 'Recode results must have one storage kind.');
        }

        return (string) array_key_first($kinds);
    }

    private function assertPredicate(Predicate $predicate, BoundSchema $schema, string $path): void
    {
        if ($predicate instanceof Comparison) {
            $this->assertNumericOperand($predicate->left, $schema, $path . '.left');
            $this->assertNumericOperand($predicate->right, $schema, $path . '.right');
            return;
        }
        if ($predicate instanceof BooleanPredicate) {
            foreach ($predicate->operands as $index => $child) {
                $this->assertPredicate($child, $schema, $path . '.operands[' . $index . ']');
            }
            return;
        }
        throw TransformationFailure::at('plan_schema_invalid', $path, 'Unsupported predicate.');
    }

    private function assertNumericOperand(Operand $operand, BoundSchema $schema, string $path): void
    {
        if ($operand instanceof LiteralOperand) {
            if (!$operand->value instanceof Binary64Value) {
                throw TransformationFailure::at('expression_type_unsupported', $path, 'Only numeric operands are executable.');
            }
            return;
        }
        if ($operand instanceof VariableOperand) {
            $variable = $schema->variable($operand->variable, $path . '.variable');
            if (!$variable->isNumeric()) {
                throw TransformationFailure::at('expression_type_unsupported', $path, 'Only numeric operands are executable.');
            }
            return;
        }
        throw TransformationFailure::at('plan_schema_invalid', $path, 'Unsupported operand.');
    }

    private function assertNumericVariable(VariableBinding $variable, string $path, string $message): void
    {
        if (!$variable->isNumeric()) {
            throw TransformationFailure::at('type_mismatch', $path, $message);
        }
    }

    private function assertValueKind(TypedValue $value, VariableBinding $variable, string $path): void
    {
        if ($this->valueKind($value) !== $variable->storageKind) {
            throw TransformationFailure::at('type_mismatch', $path, 'Typed value does not match variable storage kind.');
        }
        if ($value instanceof StringValue
            && ($variable->declaredStringWidth === null || strlen($value->value) > $variable->declaredStringWidth)
        ) {
            throw TransformationFailure::at('type_mismatch', $path, 'String value exceeds the target declared width.');
        }
    }

    private function valueKind(TypedValue $value): string
    {
        return $value instanceof Binary64Value ? 'numeric' : 'string';
    }

    private function assertCreateCapability(int $variableCount, ?int $postgresqlSlots): void
    {
        $maximum = $this->connection->profile->effectiveMaximumSourceVariables($this->connection->pdo);
        if ($variableCount >= $maximum
            || ($postgresqlSlots !== null && $postgresqlSlots >= $maximum + 1)
        ) {
            throw new UnsupportedOperation(
                DiagnosticCode::TargetCapabilityExceeded,
                $this->connection->profileName . ' cannot add another source variable to this wide table.',
            );
        }
    }

    private function createsTarget(Operation $operation): bool
    {
        return ($operation instanceof AssignOperation || $operation instanceof RecodeOperation)
            && $operation->targetMode === TargetMode::Create;
    }

    private function resolveDataset(string $datasetId): DatasetBinding
    {
        $statement = $this->statement(
            'SELECT dataset_id, dataset_name, physical_table_schema, physical_table_name FROM dataset WHERE dataset_id = ?',
        );
        CheckedPdo::execute($statement, [$datasetId], 'The transformation dataset binding could not be queried.');
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw TransformationFailure::at('invalid_dataset_id', '$.dataset_id', 'Apply dataset must resolve to exactly one catalog row.');
        }
        $row = $rows[0];
        $name = $row['dataset_name'] ?? null;
        $schema = $row['physical_table_schema'] ?? null;
        $table = $row['physical_table_name'] ?? null;
        if (($row['dataset_id'] ?? null) !== $datasetId
            || ($name !== null && !is_string($name))
            || ($schema !== null && (!is_string($schema) || $schema === ''))
            || !is_string($table)
            || $table === ''
        ) {
            throw TransformationFailure::at('invalid_catalog', '$.dataset_id', 'Dataset physical binding is malformed.');
        }

        return new DatasetBinding($datasetId, $name, $schema, $table);
    }

    private function assertExclusivePhysicalTableBinding(DatasetBinding $dataset): void
    {
        $statement = $this->statement(
            'SELECT dataset_id, physical_table_schema, physical_table_name FROM dataset',
        );
        CheckedPdo::execute($statement, [], 'Physical table ownership could not be queried.');
        $canonicalSchema = $this->canonicalPhysicalSchema($dataset->schema);
        $canonicalTable = $this->canonicalPhysicalTable($dataset->table);
        $owners = array_values(array_filter(
            $statement->fetchAll(PDO::FETCH_ASSOC),
            function (array $row) use ($canonicalSchema, $canonicalTable): bool {
                $schema = $row['physical_table_schema'] ?? null;
                $table = $row['physical_table_name'] ?? null;
                return is_string($table)
                    && $this->canonicalPhysicalTable($table) === $canonicalTable
                    && ($schema !== null && !is_string($schema)
                        || $this->canonicalPhysicalSchema($schema) === $canonicalSchema);
            },
        ));
        if (count($owners) !== 1 || ($owners[0]['dataset_id'] ?? null) !== $dataset->datasetId) {
            throw TransformationFailure::at(
                'invalid_catalog',
                '$.dataset_id',
                'The physical wide table must belong to exactly one logical dataset.',
            );
        }
    }

    private function canonicalPhysicalSchema(?string $schema): ?string
    {
        if ($this->connection->profileName === 'sqlite'
            && ($schema === null || strcasecmp($schema, 'main') === 0)
        ) {
            return 'main';
        }

        return $schema;
    }

    private function canonicalPhysicalTable(string $table): string
    {
        return $this->connection->profileName === 'sqlite' ? strtolower($table) : $table;
    }

    /** @return array<string, VariableBinding> */
    private function resolveVariables(DatasetBinding $dataset): array
    {
        $statement = $this->statement(
            'SELECT variable_id, source_name, physical_name, storage_kind, source_ordinal, declared_string_width '
            . 'FROM variable WHERE dataset_id = ? ORDER BY source_ordinal',
        );
        CheckedPdo::execute($statement, [$dataset->datasetId], 'Variable catalog bindings could not be queried.');
        $variables = [];
        $physical = [];
        $lastOrdinal = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = $row['variable_id'] ?? null;
            $name = $row['source_name'] ?? null;
            $physicalName = $row['physical_name'] ?? null;
            $kind = $row['storage_kind'] ?? null;
            $ordinal = filter_var($row['source_ordinal'] ?? null, FILTER_VALIDATE_INT);
            $width = ($row['declared_string_width'] ?? null) === null
                ? null
                : filter_var($row['declared_string_width'], FILTER_VALIDATE_INT);
            if (!is_string($id) || $id === ''
                || !is_string($name) || $name === ''
                || !is_string($physicalName) || $physicalName === ''
                || str_starts_with($physicalName, '__')
                || !in_array($kind, ['numeric', 'string'], true)
                || !is_int($ordinal) || $ordinal <= $lastOrdinal
                || ($kind === 'string' && (!is_int($width) || $width < 1))
                || isset($variables[$name]) || isset($physical[$physicalName])
            ) {
                throw TransformationFailure::at('invalid_catalog', '$.dataset_id', 'Variable catalog binding is malformed or ambiguous.');
            }
            $variables[$name] = new VariableBinding($id, $name, $physicalName, $kind, $ordinal, is_int($width) ? $width : null);
            $physical[$physicalName] = true;
            $lastOrdinal = $ordinal;
        }
        if ($variables === []) {
            throw TransformationFailure::at('invalid_catalog', '$.dataset_id', 'Bound dataset has no catalog variables.');
        }

        return $variables;
    }

    private function assertValueLabelAssociations(VariableBinding $target, string $path): void
    {
        $statement = $this->statement(
            'SELECT target_link.value_label_set_id, label_set.dataset_id AS set_dataset_id, '
            . 'linked_variable.dataset_id AS linked_dataset_id '
            . 'FROM variable_value_label_set target_link '
            . 'JOIN value_label_set label_set ON label_set.value_label_set_id = target_link.value_label_set_id '
            . 'JOIN variable_value_label_set linked_link ON linked_link.value_label_set_id = target_link.value_label_set_id '
            . 'JOIN variable linked_variable ON linked_variable.variable_id = linked_link.variable_id '
            . 'WHERE target_link.variable_id = ?',
        );
        CheckedPdo::execute($statement, [$target->variableId], 'Value-label associations could not be queried.');
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_string($row['value_label_set_id'] ?? null)
                || ($row['set_dataset_id'] ?? null) !== ($row['linked_dataset_id'] ?? null)
            ) {
                throw TransformationFailure::at(
                    'invalid_catalog',
                    $path,
                    'Value-label sets and every linked variable must belong to the same dataset.',
                );
            }
        }
    }

    /**
     * @param array<string, VariableBinding> $variables
     * @param list<string>                   $physicalColumns
     */
    private function assertPhysicalBindings(DatasetBinding $dataset, array $variables, array $physicalColumns): void
    {
        $columns = array_fill_keys($physicalColumns, true);
        if (!isset($columns['__case_ordinal'])) {
            throw TransformationFailure::at('invalid_catalog', '$.dataset_id', 'Wide table lacks the case-order column.');
        }
        foreach ($variables as $variable) {
            if (!isset($columns[$variable->physicalName])) {
                throw TransformationFailure::at('invalid_catalog', '$.dataset_id', 'Catalog variable lacks its physical column.');
            }
        }

        $selection = implode(', ', array_map(
            fn(VariableBinding $variable): string => $this->connection->profile->quoteIdentifier($variable->physicalName),
            array_values($variables),
        ));
        try {
            $statement = $this->statement(
                'SELECT ' . $selection . ' FROM ' . $dataset->qualifiedTable($this->connection->profile) . ' WHERE 1 = 0',
            );
            CheckedPdo::execute($statement, [], 'Cataloged wide-table bindings are not readable.');
        } catch (PDOException) {
            throw TransformationFailure::at('invalid_catalog', '$.dataset_id', 'Cataloged wide-table bindings are not readable.');
        }
    }

    /** @return list<string> */
    private function physicalColumns(DatasetBinding $dataset): array
    {
        if ($this->connection->profileName === 'sqlite') {
            $statement = $this->statement(
                'PRAGMA table_info(' . $this->connection->profile->quoteIdentifier($dataset->table) . ')',
            );
            CheckedPdo::execute($statement, [], 'SQLite physical columns could not be queried.');
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            return array_values(array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $rows));
        }
        if ($this->connection->profileName === 'postgresql') {
            $statement = $this->statement(
                'SELECT column_name FROM information_schema.columns '
                . 'WHERE table_schema = COALESCE(?, current_schema()) AND table_name = ? ORDER BY ordinal_position',
            );
            CheckedPdo::execute($statement, [$dataset->schema, $dataset->table], 'PostgreSQL physical columns could not be queried.');
            return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
        }

        // Existing-target plans on MySQL-family profiles need only prove the
        // cataloged columns readable; generated identifiers are never used.
        return ['__case_ordinal', ...array_map(
            static fn(VariableBinding $variable): string => $variable->physicalName,
            array_values($this->resolveVariables($dataset)),
        )];
    }

    private function postgresqlPhysicalColumnSlots(DatasetBinding $dataset): int
    {
        $statement = $this->statement('SELECT COUNT(*) FROM pg_attribute WHERE attrelid = to_regclass(?) AND attnum > 0');
        CheckedPdo::execute(
            $statement,
            [$dataset->qualifiedTable($this->connection->profile)],
            'PostgreSQL physical column slots could not be queried.',
        );
        $slots = filter_var($statement->fetchColumn(), FILTER_VALIDATE_INT);
        if (!is_int($slots) || $slots < 1) {
            throw TransformationFailure::at('invalid_catalog', '$.dataset_id', 'PostgreSQL wide table has no physical column slots.');
        }
        return $slots;
    }

    private function assertAuditReady(): void
    {
        try {
            $statement = $this->statement(
                'SELECT apply_id, contract_id, database_profile, dataset_id, physical_table_schema, physical_table_name, '
                . 'source_hash, plan_hash, canonical_plan_json, actor, status, dolt_branch, dolt_head_before, dolt_head_after, '
                . 'operation_count, started_at, completed_at FROM transformation_apply WHERE 1 = 0',
            );
            CheckedPdo::execute($statement, [], 'The transformation audit schema is not readable.');
        } catch (PDOException) {
            throw new UnsupportedOperation(
                DiagnosticCode::CatalogMigrationRequired,
                'The transformation audit schema is not ready before apply.',
            );
        }
    }

    private function assertMySqlFamilyTransactionalEngines(DatasetBinding $dataset): void
    {
        if (!in_array($this->connection->profileName, ['mysql', 'mariadb'], true)) {
            return;
        }

        $relations = [
            [$dataset->schema, $dataset->table],
            [null, 'dataset'],
            [null, 'variable'],
            [null, 'value_label_set'],
            [null, 'value_label'],
            [null, 'variable_value_label_set'],
            [null, 'transformation_apply'],
        ];
        foreach ($relations as [$schema, $table]) {
            try {
                $statement = $this->statement(
                    'SELECT table_type, engine FROM information_schema.tables '
                    . 'WHERE table_schema = COALESCE(?, DATABASE()) AND table_name = ?',
                );
                CheckedPdo::execute(
                    $statement,
                    [$schema, $table],
                    'MySQL-family storage-engine metadata could not be queried.',
                );
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $failure) {
                throw new UnsupportedOperation(
                    DiagnosticCode::SqlProfileOperationUnavailable,
                    'MySQL-family transactional storage-engine metadata is unavailable: ' . $failure->getMessage(),
                );
            }

            $row = isset($rows[0]) ? array_change_key_case($rows[0], CASE_LOWER) : [];
            if (count($rows) !== 1
                || !is_string($row['table_type'] ?? null)
                || strcasecmp($row['table_type'], 'BASE TABLE') !== 0
                || !is_string($row['engine'] ?? null)
                || strcasecmp($row['engine'], 'InnoDB') !== 0
            ) {
                throw new UnsupportedOperation(
                    DiagnosticCode::SqlProfileOperationUnavailable,
                    'MySQL-family atomic apply requires every participating data, catalog, and audit table to use InnoDB.',
                );
            }
        }
    }

    private function statement(string $sql): PDOStatement
    {
        return CheckedPdo::prepare(
            $this->connection->pdo,
            $sql,
            'Transformation preflight statement could not be prepared.',
        );
    }
}
