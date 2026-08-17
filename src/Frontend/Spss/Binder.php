<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

use OpenStatSpec\Frontend\Spss\Ast\BooleanPredicate as AstBooleanPredicate;
use OpenStatSpec\Frontend\Spss\Ast\Comparison as AstComparison;
use OpenStatSpec\Frontend\Spss\Ast\ComputeStatement;
use OpenStatSpec\Frontend\Spss\Ast\ElseInput;
use OpenStatSpec\Frontend\Spss\Ast\ExecuteStatement;
use OpenStatSpec\Frontend\Spss\Ast\ExpressionOperand as AstExpressionOperand;
use OpenStatSpec\Frontend\Spss\Ast\FormatsStatement;
use OpenStatSpec\Frontend\Spss\Ast\IfStatement;
use OpenStatSpec\Frontend\Spss\Ast\LiteralOperand as AstLiteralOperand;
use OpenStatSpec\Frontend\Spss\Ast\MissingInput;
use OpenStatSpec\Frontend\Spss\Ast\Predicate as AstPredicate;
use OpenStatSpec\Frontend\Spss\Ast\Program;
use OpenStatSpec\Frontend\Spss\Ast\RangeInput;
use OpenStatSpec\Frontend\Spss\Ast\RecodeInput;
use OpenStatSpec\Frontend\Spss\Ast\RecodeOutput;
use OpenStatSpec\Frontend\Spss\Ast\RecodeOutputKind;
use OpenStatSpec\Frontend\Spss\Ast\RecodeStatement;
use OpenStatSpec\Frontend\Spss\Ast\ScalarValue;
use OpenStatSpec\Frontend\Spss\Ast\SystemMissingInput;
use OpenStatSpec\Frontend\Spss\Ast\ValueInput;
use OpenStatSpec\Frontend\Spss\Ast\ValueLabelsStatement;
use OpenStatSpec\Frontend\Spss\Ast\VariableLabelsStatement;
use OpenStatSpec\Frontend\Spss\Ast\VariableLevelStatement;
use OpenStatSpec\Frontend\Spss\Ast\VariableOperand as AstVariableOperand;
use OpenStatSpec\Frontend\Spss\Binding\BoundCompute;
use OpenStatSpec\Frontend\Spss\Binding\BoundConditionalAssign;
use OpenStatSpec\Frontend\Spss\Binding\BoundExecute;
use OpenStatSpec\Frontend\Spss\Binding\BoundFormat;
use OpenStatSpec\Frontend\Spss\Binding\BoundMeasurementLevel;
use OpenStatSpec\Frontend\Spss\Binding\BoundProgram;
use OpenStatSpec\Frontend\Spss\Binding\BoundRecode;
use OpenStatSpec\Frontend\Spss\Binding\BoundStatement;
use OpenStatSpec\Frontend\Spss\Binding\BoundValueLabels;
use OpenStatSpec\Frontend\Spss\Binding\BoundVariableLabel;
use OpenStatSpec\Frontend\Spss\Binding\SchemaState;
use OpenStatSpec\Frontend\Spss\Number\DecimalBinary64;
use OpenStatSpec\Frontend\Spss\Request\InputSchema;
use OpenStatSpec\Frontend\Spss\Request\InputVariable;
use OpenStatSpec\Transformation\Diagnostic\SourceSpan;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Plan\Expression\BooleanPredicate;
use OpenStatSpec\Transformation\Plan\Expression\Comparison;
use OpenStatSpec\Transformation\Plan\Expression\LiteralOperand;
use OpenStatSpec\Transformation\Plan\Expression\Operand;
use OpenStatSpec\Transformation\Plan\Expression\Predicate;
use OpenStatSpec\Transformation\Plan\Expression\VariableOperand;
use OpenStatSpec\Transformation\Plan\Operation\ValueLabel;
use OpenStatSpec\Transformation\Plan\Recode\CopyResult;
use OpenStatSpec\Transformation\Plan\Recode\ExactMatch;
use OpenStatSpec\Transformation\Plan\Recode\LiteralResult;
use OpenStatSpec\Transformation\Plan\Recode\RangeMatch;
use OpenStatSpec\Transformation\Plan\Recode\RecodeMatch;
use OpenStatSpec\Transformation\Plan\Recode\RecodeRule;
use OpenStatSpec\Transformation\Plan\Recode\Result;
use OpenStatSpec\Transformation\Plan\Recode\SystemMissingMatch;
use OpenStatSpec\Transformation\Plan\Recode\SystemMissingResult;
use OpenStatSpec\Transformation\Plan\TargetMode;
use OpenStatSpec\Transformation\Plan\Value\Binary64Value;
use OpenStatSpec\Transformation\Plan\Value\StringValue;
use OpenStatSpec\Transformation\Plan\Value\TypedValue;

final class Binder
{
    public function bind(string $inputAlias, InputSchema $inputSchema, Program $program): BoundProgram
    {
        $schema = new SchemaState($inputSchema);
        $bound = [];
        foreach ($program->statements as $statement) {
            if ($statement instanceof RecodeStatement) {
                array_push($bound, ...$this->recode($statement, $schema));
                continue;
            }
            if ($statement instanceof ComputeStatement) {
                $bound[] = $this->compute($statement, $schema);
                continue;
            }
            if ($statement instanceof IfStatement) {
                $bound[] = $this->conditionalAssign($statement, $schema);
                continue;
            }
            if ($statement instanceof FormatsStatement) {
                foreach ($statement->targets as $target) {
                    $variable = $schema->resolve($target->variable, $target->variableSpan);
                    if ($variable->storageKind !== 'numeric') {
                        $this->fail(
                            'expression_type_unsupported',
                            'Numeric F formats cannot target string variables.',
                            $target->variableSpan,
                        );
                    }
                    $this->validateFormat($target->width, $target->decimals, $target->span);
                    $bound[] = new BoundFormat(
                        $variable->name,
                        $target->family,
                        $target->width,
                        $target->decimals,
                        $target->span,
                    );
                }
                continue;
            }
            if ($statement instanceof VariableLevelStatement) {
                foreach ($statement->groups as $group) {
                    foreach ($group->variables as $index => $name) {
                        $span = $group->variableSpans[$index];
                        $variable = $schema->resolve($name, $span);
                        $bound[] = new BoundMeasurementLevel($variable->name, $group->level, $span);
                    }
                }
                continue;
            }
            if ($statement instanceof ExecuteStatement) {
                $bound[] = new BoundExecute($statement->span);
                continue;
            }
            if ($statement instanceof VariableLabelsStatement) {
                foreach ($statement->assignments as $assignment) {
                    $variable = $schema->resolve($assignment->variable, $assignment->variableSpan);
                    $bound[] = new BoundVariableLabel($variable->name, $assignment->label, $assignment->span);
                }
                continue;
            }
            if ($statement instanceof ValueLabelsStatement) {
                array_push($bound, ...$this->valueLabels($statement, $schema));
                continue;
            }
            $this->fail(
                'unsupported_spss_command',
                sprintf('Unsupported AST statement %s.', $statement::class),
                new SourceSpan(0, 0, $statement->line(), 1, $statement->line(), 1),
            );
        }

        if ($bound === []) {
            $this->fail(
                'spss_syntax_error',
                'At least one supported SPSS command is required.',
                new SourceSpan(0, 0),
            );
        }

        return new BoundProgram($inputAlias, $bound);
    }

    private function compute(ComputeStatement $statement, SchemaState $schema): BoundCompute
    {
        [$value, $valueType] = $this->operand($statement->expression, $schema);
        if ($valueType !== 'binary64') {
            $this->fail(
                'expression_type_unsupported',
                'String assignment expressions are outside Frontend 0.2.',
                $statement->expression->span(),
            );
        }

        $target = $schema->find($statement->target, $statement->targetSpan);
        $this->validateTargetName($statement->target, $statement->targetSpan);
        if ($target !== null) {
            if ($target->storageKind !== 'numeric') {
                $this->fail(
                    'expression_type_unsupported',
                    'String assignment targets are outside Frontend 0.2.',
                    $statement->targetSpan,
                );
            }

            return new BoundCompute($target->name, TargetMode::Replace, $value, $statement->span);
        }
        $schema->addNumeric($statement->target);

        return new BoundCompute($statement->target, TargetMode::Create, $value, $statement->span);
    }

    private function conditionalAssign(IfStatement $statement, SchemaState $schema): BoundConditionalAssign
    {
        $condition = $this->predicate($statement->predicate, $schema);
        $target = $schema->resolve(
            $statement->target,
            $statement->targetSpan,
            'conditional_target_missing',
        );
        $this->validateTargetName($statement->target, $statement->targetSpan);
        if ($target->storageKind !== 'numeric') {
            $this->fail(
                'expression_type_unsupported',
                'String assignment targets are outside Frontend 0.2.',
                $statement->targetSpan,
            );
        }
        [$value, $valueType] = $this->operand($statement->expression, $schema);
        if ($valueType !== 'binary64') {
            $this->fail(
                'expression_type_unsupported',
                'String assignment expressions are outside Frontend 0.2.',
                $statement->expression->span(),
            );
        }

        return new BoundConditionalAssign($condition, $target->name, $value, $statement->span);
    }

    /** @return array{Operand, 'binary64'|'string'} */
    private function operand(AstExpressionOperand $operand, SchemaState $schema): array
    {
        if ($operand instanceof AstLiteralOperand) {
            return [
                new LiteralOperand(Binary64Value::fromBits(DecimalBinary64::bits($operand->token))),
                'binary64',
            ];
        }
        if ($operand instanceof AstVariableOperand) {
            $variable = $schema->resolve($operand->name, $operand->span);

            return [
                new VariableOperand($variable->name),
                $this->storageType($variable),
            ];
        }

        throw new \LogicException(sprintf('Unsupported operand %s.', $operand::class));
    }

    private function predicate(AstPredicate $predicate, SchemaState $schema): Predicate
    {
        if ($predicate instanceof AstComparison) {
            [$left, $leftType] = $this->operand($predicate->left, $schema);
            [$right, $rightType] = $this->operand($predicate->right, $schema);
            if ($leftType !== 'binary64' || $rightType !== 'binary64') {
                $this->fail(
                    'expression_type_unsupported',
                    'String predicates are outside Frontend 0.2.',
                    $predicate->span,
                );
            }

            return new Comparison($left, $predicate->operator, $right);
        }
        if ($predicate instanceof AstBooleanPredicate) {
            return new BooleanPredicate(
                $predicate->operator,
                ...array_map(fn(AstPredicate $operand): Predicate => $this->predicate($operand, $schema), $predicate->operands),
            );
        }

        throw new \LogicException(sprintf('Unsupported predicate %s.', $predicate::class));
    }

    /** @return non-empty-list<BoundRecode> */
    private function recode(RecodeStatement $statement, SchemaState $schema): array
    {
        $sources = [];
        foreach ($statement->sources as $index => $sourceName) {
            $sources[] = $schema->resolve($sourceName, $statement->sourceSpans[$index]);
        }

        $targetMode = $statement->targets === [] ? TargetMode::Replace : TargetMode::Create;
        if ($targetMode === TargetMode::Create && count($statement->targets) !== count($sources)) {
            $this->fail(
                'spss_syntax_error',
                'RECODE INTO requires one target for each source.',
                $statement->span,
            );
        }
        $targetNames = $targetMode === TargetMode::Create
            ? $statement->targets
            : array_map(static fn(InputVariable $source): string => $source->name, $sources);
        $seenTargets = [];
        foreach ($targetNames as $index => $targetName) {
            $targetSpan = $targetMode === TargetMode::Create
                ? $statement->targetSpans[$index]
                : $statement->sourceSpans[$index];
            $this->validateTargetName($targetName, $targetSpan);
            if ($targetMode === TargetMode::Create) {
                $key = strtolower($targetName);
                if ($schema->contains($targetName) || isset($seenTargets[$key])) {
                    $this->fail(
                        'target_already_exists',
                        'RECODE INTO targets must be fresh and unique in the pre-command schema.',
                        $targetSpan,
                    );
                }
                $seenTargets[$key] = true;
            }
        }

        $elseRules = array_values(array_filter(
            $statement->rules,
            static fn(\OpenStatSpec\Frontend\Spss\Ast\RecodeRule $rule): bool => $rule->input instanceof ElseInput,
        ));
        if (count($elseRules) > 1) {
            $this->fail('duplicate_else', 'ELSE may occur only once.', $elseRules[1]->input->span());
        }
        if (
            $elseRules !== []
            && !($statement->rules[array_key_last($statement->rules)]->input instanceof ElseInput)
        ) {
            $this->fail('else_not_last', 'ELSE must be the final RECODE rule.', $elseRules[0]->input->span());
        }
        if (count($statement->rules) === count($elseRules)) {
            $this->fail(
                'spss_syntax_error',
                'RECODE requires at least one non-ELSE rule.',
                $statement->span,
            );
        }

        $bound = [];
        foreach ($sources as $index => $source) {
            $rules = [];
            $unmatched = null;
            foreach ($statement->rules as $rule) {
                $result = $this->recodeResult($rule->output, $source, $targetMode);
                if ($rule->input instanceof ElseInput) {
                    $unmatched = $result;
                    continue;
                }
                $rules[] = new RecodeRule($this->recodeMatch($rule->input, $source), $result);
            }
            $unmatched ??= $targetMode === TargetMode::Create ? new SystemMissingResult() : new CopyResult();
            $resultTypes = array_values(array_unique(array_map(
                fn(Result $result): string => $this->resultType($result, $source),
                [...array_map(static fn(RecodeRule $rule): Result => $rule->result, $rules), $unmatched],
            )));
            $targetSpan = $targetMode === TargetMode::Create
                ? $statement->targetSpans[$index]
                : $statement->sourceSpans[$index];
            if ($targetMode === TargetMode::Create && in_array('string', $resultTypes, true)) {
                $this->fail(
                    'string_target_requires_declaration',
                    'New string targets require an explicit declaration outside Frontend 0.2.',
                    $targetSpan,
                );
            }
            if (count($resultTypes) !== 1) {
                $this->fail(
                    'mixed_result_types',
                    'All RECODE results, including unmatched behavior, must have one type.',
                    $statement->span,
                );
            }
            if ($targetMode === TargetMode::Replace && $resultTypes[0] !== $this->storageType($source)) {
                $this->fail(
                    'type_mismatch',
                    'In-place RECODE cannot change the variable storage kind.',
                    $statement->span,
                );
            }
            $bound[] = new BoundRecode(
                $source->name,
                $targetNames[$index],
                $targetMode,
                $this->nonEmptyRules($rules, $statement->span),
                $unmatched,
                $statement->span,
            );
        }

        if ($targetMode === TargetMode::Create) {
            foreach ($targetNames as $targetName) {
                $schema->addNumeric($targetName);
            }
        }

        return $bound;
    }

    private function recodeMatch(RecodeInput $input, InputVariable $source): RecodeMatch
    {
        if ($input instanceof MissingInput) {
            $this->fail(
                'spss_syntax_error',
                'MISSING includes user-missing values and is outside the bounded frontend.',
                $input->span,
            );
        }
        if ($input instanceof SystemMissingInput) {
            if ($source->storageKind === 'string') {
                $this->fail(
                    'system_missing_for_string',
                    'SYSMIS cannot match a string variable.',
                    $input->span,
                );
            }

            return new SystemMissingMatch();
        }
        if ($input instanceof RangeInput) {
            if ($input->lower === null || $input->upper === null) {
                $this->fail('spss_syntax_error', 'RECODE ranges require two finite endpoints.', $input->span);
            }
            $lower = $this->typedValue($input->lower);
            $upper = $this->typedValue($input->upper);
            if (!$lower instanceof Binary64Value || !$upper instanceof Binary64Value || $source->storageKind !== 'numeric') {
                $this->fail('type_mismatch', 'RECODE ranges require numeric sources and endpoints.', $input->span);
            }
            if ($lower->number() > $upper->number()) {
                $this->fail('invalid_numeric_range', 'RECODE range lower bound exceeds its upper bound.', $input->span);
            }

            return new RangeMatch($lower, $upper);
        }
        if ($input instanceof ValueInput) {
            $values = array_map(fn(ScalarValue $value): TypedValue => $this->typedValue($value), $input->values);
            $expectedType = $this->storageType($source);
            foreach ($values as $value) {
                if ($this->typedValueType($value) !== $expectedType) {
                    $this->fail('type_mismatch', 'RECODE match values must match the source type.', $input->span);
                }
            }

            return new ExactMatch(...$values);
        }

        throw new \LogicException(sprintf('Unsupported recode input %s.', $input::class));
    }

    private function recodeResult(RecodeOutput $output, InputVariable $source, TargetMode $targetMode): Result
    {
        if ($output->kind === RecodeOutputKind::Copy) {
            return new CopyResult();
        }
        if ($output->kind === RecodeOutputKind::SystemMissing) {
            if ($source->storageKind === 'string' && $targetMode === TargetMode::Replace) {
                $this->fail(
                    'system_missing_for_string',
                    'SYSMIS cannot be produced for a string variable.',
                    $output->span,
                );
            }

            return new SystemMissingResult();
        }

        return new LiteralResult($this->typedValue(
            $output->value ?? throw new \LogicException('A literal RECODE result requires a value.'),
        ));
    }

    private function resultType(Result $result, InputVariable $source): string
    {
        if ($result instanceof CopyResult) {
            return $this->storageType($source);
        }
        if ($result instanceof SystemMissingResult) {
            return 'binary64';
        }
        if ($result instanceof LiteralResult) {
            return $this->typedValueType($result->value);
        }

        throw new \LogicException(sprintf('Unsupported recode result %s.', $result::class));
    }

    /** @return list<BoundValueLabels> */
    private function valueLabels(ValueLabelsStatement $statement, SchemaState $schema): array
    {
        $bound = [];
        foreach ($statement->groups as $group) {
            $labels = array_map(
                fn(\OpenStatSpec\Frontend\Spss\Ast\ValueLabel $label): ValueLabel => new ValueLabel(
                    $this->typedValue($label->value),
                    $label->label,
                ),
                $group->labels,
            );
            foreach ($group->variables as $index => $name) {
                $variable = $schema->resolve($name, $group->variableSpans[$index]);
                $expectedType = $this->storageType($variable);
                $seen = [];
                foreach ($labels as $label) {
                    if ($this->typedValueType($label->value) !== $expectedType) {
                        $this->fail(
                            'type_mismatch',
                            'VALUE LABELS codes must match the variable storage kind.',
                            $group->span,
                        );
                    }
                    $key = $label->value->canonicalKey();
                    if (isset($seen[$key])) {
                        $this->fail(
                            'duplicate_value_label',
                            'VALUE LABELS contains duplicate canonical codes.',
                            $group->span,
                        );
                    }
                    $seen[$key] = true;
                }
                $bound[] = new BoundValueLabels($variable->name, $labels, $group->span);
            }
        }

        return $bound;
    }

    private function typedValue(ScalarValue $value): TypedValue
    {
        if ($value->isNumeric()) {
            return Binary64Value::fromBits(DecimalBinary64::bits(
                $value->numericToken ?? throw new \LogicException('Numeric scalar has no source token.'),
            ));
        }

        return new StringValue((string) $value->value);
    }

    private function typedValueType(TypedValue $value): string
    {
        return $value instanceof Binary64Value ? 'binary64' : 'string';
    }

    /** @return 'binary64'|'string' */
    private function storageType(InputVariable $variable): string
    {
        return $variable->storageKind === 'numeric' ? 'binary64' : 'string';
    }

    private function validateTargetName(string $name, SourceSpan $span): void
    {
        if (str_starts_with($name, '__')) {
            $this->fail('reserved_target_name', 'Target names beginning with __ are reserved.', $span);
        }
    }

    private function validateFormat(int $width, int $decimals, SourceSpan $span): void
    {
        if (
            $width < 1
            || $width > 40
            || $decimals < 0
            || $decimals > 16
            || ($decimals > 0 && $width < $decimals + 2)
        ) {
            $this->fail('invalid_format', 'Invalid SPSS F format.', $span);
        }
    }

    /**
     * @param list<RecodeRule> $rules
     * @return non-empty-list<RecodeRule>
     */
    private function nonEmptyRules(array $rules, SourceSpan $span): array
    {
        if ($rules === []) {
            $this->fail('spss_syntax_error', 'RECODE requires at least one non-ELSE rule.', $span);
        }

        return $rules;
    }

    private function fail(string $code, string $message, SourceSpan $span): never
    {
        throw TransformationFailure::at($code, '$.source_text', $message, $span);
    }
}
