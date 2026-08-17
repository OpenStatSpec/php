<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan;

use JsonException;
use OpenStatSpec\Transformation\Canonical\CanonicalJson;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Plan\Expression\BooleanPredicate;
use OpenStatSpec\Transformation\Plan\Expression\Comparison;
use OpenStatSpec\Transformation\Plan\Expression\LiteralOperand;
use OpenStatSpec\Transformation\Plan\Expression\Operand;
use OpenStatSpec\Transformation\Plan\Expression\Predicate;
use OpenStatSpec\Transformation\Plan\Expression\VariableOperand;
use OpenStatSpec\Transformation\Plan\Operation\AssignOperation;
use OpenStatSpec\Transformation\Plan\Operation\ConditionalAssignOperation;
use OpenStatSpec\Transformation\Plan\Operation\ExecuteOperation;
use OpenStatSpec\Transformation\Plan\Operation\RecodeOperation;
use OpenStatSpec\Transformation\Plan\Operation\ReplaceValueLabelsOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetFormatOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetMeasurementLevelOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetVariableLabelOperation;
use OpenStatSpec\Transformation\Plan\Operation\ValueLabel;
use OpenStatSpec\Transformation\Plan\Recode\CopyResult;
use OpenStatSpec\Transformation\Plan\Recode\ExactMatch;
use OpenStatSpec\Transformation\Plan\Recode\LiteralResult;
use OpenStatSpec\Transformation\Plan\Recode\RecodeMatch;
use OpenStatSpec\Transformation\Plan\Recode\RangeMatch;
use OpenStatSpec\Transformation\Plan\Recode\RecodeRule;
use OpenStatSpec\Transformation\Plan\Recode\Result;
use OpenStatSpec\Transformation\Plan\Recode\SystemMissingMatch;
use OpenStatSpec\Transformation\Plan\Recode\SystemMissingResult;
use OpenStatSpec\Transformation\Plan\Value\Binary64Value;
use OpenStatSpec\Transformation\Plan\Value\StringValue;
use OpenStatSpec\Transformation\Plan\Value\TypedValue;

final class PlanCodec
{
    public function fromJson(string $json): TransformationPlan
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->schema('$', 'Plan JSON must be valid JSON.');
        }

        if (!is_array($decoded)) {
            $this->schema('$', 'Plan must be a JSON object.');
        }

        return $this->fromArray($decoded);
    }

    /** @param array<string, mixed> $plan */
    public function fromArray(array $plan): TransformationPlan
    {
        $this->exactKeys($plan, ['contract', 'input_alias', 'operations'], '$');
        $contract = PlanContract::tryFrom($this->string($plan['contract'], '$.contract'));
        if ($contract === null) {
            $this->schema('$.contract', 'Plan contract must be openstatspec-transformation-plan-v0.1 or openstatspec-transformation-plan-v0.2.');
        }

        $inputAlias = $this->nonEmptyString($plan['input_alias'], '$.input_alias');
        $rawOperations = $this->nonEmptyList($plan['operations'], '$.operations');
        $operations = [];
        foreach ($rawOperations as $index => $rawOperation) {
            $path = '$.operations[' . $index . ']';
            $operation = $this->operation($rawOperation, $path);
            if ($contract === PlanContract::V01 && $operation->minimumContract() !== PlanContract::V01) {
                $this->schema($path, 'Plan contract v0.1 cannot contain v0.2 operations.');
            }
            $operations[] = $operation;
        }

        return new TransformationPlan($contract, $inputAlias, $operations);
    }

    public function canonicalJson(TransformationPlan $plan): string
    {
        return CanonicalJson::encode($plan->canonicalArray());
    }

    public function hash(TransformationPlan $plan): string
    {
        return hash('sha256', $this->canonicalJson($plan));
    }

    private function operation(mixed $raw, string $path): Operation
    {
        $object = $this->object($raw, $path);
        $op = $this->string($object['op'] ?? null, $path . '.op');

        return match ($op) {
            'recode' => $this->recodeOperation($object, $path),
            'assign' => $this->assignOperation($object, $path),
            'conditional_assign' => $this->conditionalAssignOperation($object, $path),
            'set_variable_label' => $this->setVariableLabelOperation($object, $path),
            'replace_value_labels' => $this->replaceValueLabelsOperation($object, $path),
            'set_format' => $this->setFormatOperation($object, $path),
            'set_measurement_level' => $this->setMeasurementLevelOperation($object, $path),
            'execute' => $this->executeOperation($object, $path),
            default => $this->schema($path . '.op', 'Unknown operation.'),
        };
    }

    /** @param array<string, mixed> $object */
    private function assignOperation(array $object, string $path): AssignOperation
    {
        $this->exactKeys($object, ['op', 'target', 'target_mode', 'value'], $path);
        $mode = TargetMode::tryFrom($this->string($object['target_mode'], $path . '.target_mode'));
        if ($mode === null) {
            $this->schema($path . '.target_mode', 'Assign target mode must be create or replace.');
        }

        return new AssignOperation(
            $this->targetName($object['target'], $path . '.target'),
            $mode,
            $this->numericOperand($object['value'], $path . '.value'),
        );
    }

    /** @param array<string, mixed> $object */
    private function conditionalAssignOperation(array $object, string $path): ConditionalAssignOperation
    {
        $this->exactKeys($object, ['op', 'condition', 'target', 'value'], $path);

        return new ConditionalAssignOperation(
            $this->predicate($object['condition'], $path . '.condition'),
            $this->targetName($object['target'], $path . '.target'),
            $this->numericOperand($object['value'], $path . '.value'),
        );
    }

    /** @param array<string, mixed> $object */
    private function setFormatOperation(array $object, string $path): SetFormatOperation
    {
        $this->exactKeys($object, ['op', 'variable', 'family', 'width', 'decimals'], $path);
        $family = $this->string($object['family'], $path . '.family');
        if ($family !== 'F') {
            $this->schema($path . '.family', 'The bounded format profile supports numeric F formats only.');
        }

        $operation = new SetFormatOperation(
            $this->nonEmptyString($object['variable'], $path . '.variable'),
            $family,
            $this->integer($object['width'], $path . '.width'),
            $this->integer($object['decimals'], $path . '.decimals'),
        );
        if ($operation->width < 1 || $operation->width > 40
            || $operation->decimals < 0 || $operation->decimals > 16
            || ($operation->decimals > 0 && $operation->width < $operation->decimals + 2)) {
            throw TransformationFailure::at('invalid_format', $path, 'Invalid SPSS F format.');
        }

        return $operation;
    }

    /** @param array<string, mixed> $object */
    private function setMeasurementLevelOperation(array $object, string $path): SetMeasurementLevelOperation
    {
        $this->exactKeys($object, ['op', 'variable', 'level'], $path);
        $level = $this->string($object['level'], $path . '.level');
        if (!in_array($level, ['nominal', 'ordinal', 'scale'], true)) {
            $this->schema($path . '.level', 'Measurement level must be nominal, ordinal, or scale.');
        }

        return new SetMeasurementLevelOperation(
            $this->nonEmptyString($object['variable'], $path . '.variable'),
            $level,
        );
    }

    /** @param array<string, mixed> $object */
    private function executeOperation(array $object, string $path): ExecuteOperation
    {
        $this->exactKeys($object, ['op'], $path);
        return new ExecuteOperation();
    }

    private function predicate(mixed $raw, string $path): Predicate
    {
        $object = $this->object($raw, $path);
        $expression = $this->string($object['expression'] ?? null, $path . '.expression');

        if ($expression === 'comparison') {
            $this->exactKeys($object, ['expression', 'left', 'operator', 'right'], $path);
            return new Comparison(
                $this->numericOperand($object['left'], $path . '.left'),
                $this->string($object['operator'], $path . '.operator'),
                $this->numericOperand($object['right'], $path . '.right'),
            );
        }

        if ($expression === 'boolean') {
            $this->exactKeys($object, ['expression', 'operator', 'operands'], $path);
            $operator = $this->string($object['operator'], $path . '.operator');
            $rawOperands = $this->nonEmptyList($object['operands'], $path . '.operands');
            if (count($rawOperands) < 2) {
                $this->schema($path . '.operands', 'A boolean expression requires at least two operands.');
            }
            $operands = [];
            foreach ($rawOperands as $index => $rawOperand) {
                $operand = $this->predicate($rawOperand, $path . '.operands[' . $index . ']');
                if ($operand instanceof BooleanPredicate && $operand->operator === $operator) {
                    throw TransformationFailure::at(
                        'noncanonical_boolean_shape',
                        $path . '.operands[' . $index . ']',
                        'A same-operator boolean chain must be flattened in source order.',
                    );
                }
                $operands[] = $operand;
            }

            return new BooleanPredicate($operator, ...$operands);
        }

        $this->schema($path . '.expression', 'Unknown predicate expression.');
    }

    private function numericOperand(mixed $raw, string $path): Operand
    {
        $operand = $this->operand($raw, $path);
        if ($operand instanceof LiteralOperand && $operand->value instanceof StringValue) {
            throw TransformationFailure::at(
                'expression_type_unsupported',
                $path,
                'String literal operands are outside the bounded expression profile.',
            );
        }

        return $operand;
    }

    private function operand(mixed $raw, string $path): Operand
    {
        $object = $this->object($raw, $path);
        $kind = $this->string($object['kind'] ?? null, $path . '.kind');
        if ($kind === 'variable') {
            $this->exactKeys($object, ['kind', 'variable'], $path);
            return new VariableOperand($this->nonEmptyString($object['variable'], $path . '.variable'));
        }
        if ($kind === 'literal') {
            $this->exactKeys($object, ['kind', 'value'], $path);
            return new LiteralOperand($this->typedValue($object['value'], $path . '.value'));
        }

        $this->schema($path . '.kind', 'Operand kind must be variable or literal.');
    }

    private function targetName(mixed $value, string $path): string
    {
        $target = $this->nonEmptyString($value, $path);
        if (str_starts_with($target, '__')) {
            throw TransformationFailure::at('reserved_target_name', $path, 'Target name is reserved.');
        }

        return $target;
    }

    /** @param array<string, mixed> $object */
    private function recodeOperation(array $object, string $path): RecodeOperation
    {
        $this->exactKeys($object, ['op', 'source', 'target', 'target_mode', 'rules', 'unmatched'], $path);
        $mode = $this->string($object['target_mode'], $path . '.target_mode');
        if (($targetMode = TargetMode::tryFrom($mode)) === null) {
            $this->schema($path . '.target_mode', 'Recode target mode must be create or replace.');
        }

        $rawRules = $this->nonEmptyList($object['rules'], $path . '.rules');
        $rules = [];
        foreach ($rawRules as $index => $rawRule) {
            $rules[] = $this->rule($rawRule, $path . '.rules[' . $index . ']');
        }

        return new RecodeOperation(
            $this->nonEmptyString($object['source'], $path . '.source'),
            $this->targetName($object['target'], $path . '.target'),
            $targetMode,
            $rules,
            $this->result($object['unmatched'], $path . '.unmatched'),
        );
    }

    /** @param array<string, mixed> $object */
    private function setVariableLabelOperation(array $object, string $path): SetVariableLabelOperation
    {
        $this->exactKeys($object, ['op', 'variable', 'label'], $path);

        return new SetVariableLabelOperation(
            $this->nonEmptyString($object['variable'], $path . '.variable'),
            $this->string($object['label'], $path . '.label'),
        );
    }

    /** @param array<string, mixed> $object */
    private function replaceValueLabelsOperation(array $object, string $path): ReplaceValueLabelsOperation
    {
        $this->exactKeys($object, ['op', 'variable', 'labels'], $path);
        $rawLabels = $this->nonEmptyList($object['labels'], $path . '.labels');
        $labels = [];
        $seen = [];
        foreach ($rawLabels as $index => $rawLabel) {
            $label = $this->valueLabel($rawLabel, $path . '.labels[' . $index . ']');
            $key = $label->value->canonicalKey();
            if (isset($seen[$key])) {
                throw TransformationFailure::at(
                    'duplicate_value_label',
                    $path . '.labels[' . $index . '].value',
                    'Value labels must have unique exact typed values.',
                );
            }
            $seen[$key] = true;
            $labels[] = $label;
        }

        return new ReplaceValueLabelsOperation(
            $this->nonEmptyString($object['variable'], $path . '.variable'),
            $labels,
        );
    }

    private function rule(mixed $raw, string $path): RecodeRule
    {
        $object = $this->object($raw, $path);
        $this->exactKeys($object, ['match', 'result'], $path);

        return new RecodeRule(
            $this->recodeMatch($object['match'], $path . '.match'),
            $this->result($object['result'], $path . '.result'),
        );
    }

    private function recodeMatch(mixed $raw, string $path): RecodeMatch
    {
        $object = $this->object($raw, $path);
        $kind = $this->string($object['kind'] ?? null, $path . '.kind');

        if ($kind === 'values') {
            $this->exactKeys($object, ['kind', 'values'], $path);
            $rawValues = $this->nonEmptyList($object['values'], $path . '.values');
            $values = [];
            foreach ($rawValues as $index => $rawValue) {
                $values[] = $this->typedValue($rawValue, $path . '.values[' . $index . ']');
            }
            return new ExactMatch(...$values);
        }

        if ($kind === 'range') {
            $this->exactKeys($object, ['kind', 'lower', 'upper'], $path);
            $lower = $this->typedValue($object['lower'], $path . '.lower');
            $upper = $this->typedValue($object['upper'], $path . '.upper');
            if (!$lower instanceof Binary64Value || !$upper instanceof Binary64Value) {
                $this->schema($path, 'Range endpoints must be binary64 values.');
            }
            if ($lower->number() > $upper->number()) {
                throw TransformationFailure::at(
                    'invalid_numeric_range',
                    $path,
                    'Range lower endpoint cannot exceed its upper endpoint.',
                );
            }
            return new RangeMatch($lower, $upper);
        }

        if ($kind === 'system_missing') {
            $this->exactKeys($object, ['kind'], $path);
            return new SystemMissingMatch();
        }

        $this->schema($path . '.kind', 'Unknown match kind.');
    }

    private function result(mixed $raw, string $path): Result
    {
        $object = $this->object($raw, $path);
        $kind = $this->string($object['kind'] ?? null, $path . '.kind');

        return match ($kind) {
            'literal' => $this->literalResult($object, $path),
            'copy' => $this->emptyResult($object, $path, new CopyResult()),
            'system_missing' => $this->emptyResult($object, $path, new SystemMissingResult()),
            default => $this->schema($path . '.kind', 'Unknown result kind.'),
        };
    }

    /** @param array<string, mixed> $object */
    private function literalResult(array $object, string $path): LiteralResult
    {
        $this->exactKeys($object, ['kind', 'value'], $path);
        return new LiteralResult($this->typedValue($object['value'], $path . '.value'));
    }

    /** @param array<string, mixed> $object */
    private function emptyResult(array $object, string $path, Result $result): Result
    {
        $this->exactKeys($object, ['kind'], $path);
        return $result;
    }

    private function valueLabel(mixed $raw, string $path): ValueLabel
    {
        $object = $this->object($raw, $path);
        $this->exactKeys($object, ['value', 'label'], $path);

        return new ValueLabel(
            $this->typedValue($object['value'], $path . '.value'),
            $this->string($object['label'], $path . '.label'),
        );
    }

    private function typedValue(mixed $raw, string $path): TypedValue
    {
        $object = $this->object($raw, $path);
        $type = $this->string($object['type'] ?? null, $path . '.type');
        if ($type === 'binary64') {
            $this->exactKeys($object, ['type', 'bits'], $path);
            $bits = $this->string($object['bits'], $path . '.bits');
            try {
                return Binary64Value::fromBits($bits);
            } catch (TransformationFailure $failure) {
                $this->schema($path . '.bits', $failure->getMessage());
            }
        }
        if ($type === 'string') {
            $this->exactKeys($object, ['type', 'value'], $path);
            return new StringValue($this->string($object['value'], $path . '.value'));
        }

        $this->schema($path . '.type', 'Typed value type must be binary64 or string.');
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $path): array
    {
        if (!is_array($value) || array_is_list($value)) {
            $this->schema($path, 'Value must be an object.');
        }
        return $value;
    }

    /** @return non-empty-list<mixed> */
    private function nonEmptyList(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            $this->schema($path, 'Value must be a non-empty array.');
        }
        return $value;
    }

    private function integer(mixed $value, string $path): int
    {
        if (!is_int($value)) {
            $this->schema($path, 'Value must be an integer.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $object
     * @param list<string> $expected
     */
    private function exactKeys(array $object, array $expected, string $path): void
    {
        $actual = array_keys($object);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            $this->schema($path, 'Object has missing or extra members.');
        }
    }

    private function nonEmptyString(mixed $value, string $path): string
    {
        $string = $this->string($value, $path);
        if ($string === '') {
            $this->schema($path, 'String must not be empty.');
        }
        return $string;
    }

    private function string(mixed $value, string $path): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            $this->schema($path, 'Value must be a valid UTF-8 string.');
        }
        return $value;
    }

    private function schema(string $path, string $message): never
    {
        throw TransformationFailure::at('plan_schema_invalid', $path, $message);
    }
}
