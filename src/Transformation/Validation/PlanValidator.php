<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Validation;

use OpenStatSpec\Transformation\Model\Action\AssignValueAction;
use OpenStatSpec\Transformation\Model\Action\CopySourceAction;
use OpenStatSpec\Transformation\Model\Action\SetMissingAction;
use OpenStatSpec\Transformation\Model\CreateVariableOperation;
use OpenStatSpec\Transformation\Model\DeleteVariableOperation;
use OpenStatSpec\Transformation\Model\RecodeAction;
use OpenStatSpec\Transformation\Model\RecodeOperation;
use OpenStatSpec\Transformation\Model\RecodeSelector;
use OpenStatSpec\Transformation\Model\ScalarValue;
use OpenStatSpec\Transformation\Model\Selector\ElseSelector;
use OpenStatSpec\Transformation\Model\Selector\ExactValueSelector;
use OpenStatSpec\Transformation\Model\Selector\MissingValueSelector;
use OpenStatSpec\Transformation\Model\Selector\NumericRangeSelector;
use OpenStatSpec\Transformation\Model\SetValueLabelsOperation;
use OpenStatSpec\Transformation\Model\SetVariableLabelOperation;
use OpenStatSpec\Transformation\Model\TransformationOperation;
use OpenStatSpec\Transformation\Model\TransformationPlan;

/** Strict structural and semantic validation for the canonical plan contract. */
final class PlanValidator
{
    /** @var list<ValidationViolation> */
    private array $violations = [];

    public function validate(TransformationPlan $plan): ValidationResult
    {
        $this->violations = [];
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $plan->datasetId()) !== 1) {
            $this->violation(
                'dataset_id.invalid_uuid',
                '$.dataset_id',
                'The dataset identity must be a canonical lowercase RFC 9562 UUID string.',
            );
        }

        if ($plan->operations() === []) {
            $this->violation('operations.empty', '$.operations', 'A transformation plan must contain at least one operation.');
        }

        foreach ($plan->operations() as $index => $operation) {
            $path = '$.operations[' . $index . ']';
            $this->validateOperation($operation, $path);
        }

        return new ValidationResult($this->violations);
    }

    public function assertValid(TransformationPlan $plan): void
    {
        $this->validate($plan)->throwIfInvalid();
    }

    private function validateOperation(TransformationOperation $operation, string $path): void
    {
        if (!in_array($operation::class, [
            CreateVariableOperation::class,
            DeleteVariableOperation::class,
            RecodeOperation::class,
            SetVariableLabelOperation::class,
            SetValueLabelsOperation::class,
        ], true)) {
            $this->violation(
                'operation.unsupported_type',
                $path . '.type',
                'Only canonical, source-language-neutral operation types are permitted.',
            );

            return;
        }

        $this->validateVariableName($operation->sourceVariable(), $path . '.source_variable');
        $this->validateVariableName($operation->targetVariable(), $path . '.target_variable');

        if ($operation instanceof CreateVariableOperation) {
            if ($operation->storageKind() === 'string') {
                $width = $operation->declaredStringWidth();
                if ($width === null || $width < 1) {
                    $this->violation('create_variable.string_width_required', $path . '.declared_string_width', 'String variables require a positive declared string width.');
                } elseif ($width > CreateVariableOperation::MAX_STRING_WIDTH) {
                    $this->violation('create_variable.string_width_too_large', $path . '.declared_string_width', 'String variables support at most ' . CreateVariableOperation::MAX_STRING_WIDTH . ' bytes.');
                }
            }
        } elseif ($operation instanceof DeleteVariableOperation) {
            return;
        } elseif ($operation instanceof RecodeOperation) {
            $this->validateRecode($operation, $path);
        } elseif ($operation instanceof SetVariableLabelOperation) {
            $this->validateText($operation->label(), $path . '.label');
        } elseif ($operation instanceof SetValueLabelsOperation) {
            $this->validateValueLabels($operation, $path);
        }
    }

    private function validateRecode(RecodeOperation $operation, string $path): void
    {
        if ($operation->rules() === []) {
            $this->violation('recode.rules_empty', $path . '.rules', 'A recode operation must contain ordered rules.');

            return;
        }

        /** @var array<string, int> $exactValues */
        $exactValues = [];
        /** @var list<array{index: int, lower: ?float, upper: ?float}> $ranges */
        $ranges = [];
        $missingIndex = null;
        $elseIndexes = [];
        foreach ($operation->rules() as $index => $rule) {
            $rulePath = $path . '.rules[' . $index . ']';
            $selector = $rule->selector();
            $this->validateSelector($selector, $rulePath . '.selector');
            $this->validateAction($rule->action(), $rulePath . '.action');

            if ($selector instanceof ExactValueSelector) {
                $identity = $selector->value()->identity();
                if (isset($exactValues[$identity])) {
                    $this->violation('recode.ambiguous_mapping', $rulePath . '.selector', 'An exact source value is mapped more than once.');
                } else {
                    $exactValues[$identity] = $index;
                }
            } elseif ($selector instanceof NumericRangeSelector) {
                $ranges[] = ['index' => $index, 'lower' => $selector->lower(), 'upper' => $selector->upper()];
            } elseif ($selector instanceof MissingValueSelector) {
                if ($missingIndex !== null) {
                    $this->violation('recode.ambiguous_mapping', $rulePath . '.selector', 'The system-missing value is mapped more than once.');
                }
                $missingIndex = $index;
            } elseif ($selector instanceof ElseSelector) {
                $elseIndexes[] = $index;
            }
        }

        if (count($elseIndexes) !== 1) {
            $this->violation('recode.else_required_once', $path . '.rules', 'A recode must contain exactly one explicit else rule.');
        } elseif ($elseIndexes[0] !== array_key_last($operation->rules())) {
            $this->violation('recode.else_not_last', $path . '.rules[' . $elseIndexes[0] . ']', 'The else rule must be last.');
        }

        $this->validateRangeAmbiguity($ranges, $exactValues, $operation, $path);
    }

    private function validateSelector(RecodeSelector $selector, string $path): void
    {
        if (!in_array($selector::class, [
            ExactValueSelector::class,
            NumericRangeSelector::class,
            MissingValueSelector::class,
            ElseSelector::class,
        ], true)) {
            $this->violation('selector.unsupported_type', $path . '.type', 'The selector is not part of the canonical source-neutral contract.');

            return;
        }

        if ($selector instanceof ExactValueSelector) {
            $this->validateScalar($selector->value(), $path . '.value');
        } elseif ($selector instanceof NumericRangeSelector) {
            $lower = $selector->lower();
            $upper = $selector->upper();
            if ($lower === null && $upper === null) {
                $this->violation('selector.range_unbounded', $path, 'A numeric range must have at least one finite bound.');
            }
            if (($lower !== null && !is_finite($lower)) || ($upper !== null && !is_finite($upper))) {
                $this->violation('selector.range_non_finite', $path, 'Numeric range bounds must be finite.');
            } elseif ($lower !== null && $upper !== null && $lower > $upper) {
                $this->violation('selector.range_reversed', $path, 'A numeric range lower bound must not exceed its upper bound.');
            }
        }
    }

    private function validateAction(RecodeAction $action, string $path): void
    {
        if (!in_array($action::class, [
            AssignValueAction::class,
            SetMissingAction::class,
            CopySourceAction::class,
        ], true)) {
            $this->violation('action.unsupported_type', $path . '.type', 'The action is not part of the canonical source-neutral contract.');

            return;
        }

        if ($action instanceof AssignValueAction) {
            $this->validateScalar($action->value(), $path . '.value');
        }
    }

    private function validateValueLabels(SetValueLabelsOperation $operation, string $path): void
    {
        /** @var array<string, true> $values */
        $values = [];
        foreach ($operation->labels() as $index => $label) {
            $labelPath = $path . '.labels[' . $index . ']';
            $this->validateScalar($label->value(), $labelPath . '.value');
            $this->validateText($label->label(), $labelPath . '.label');
            $identity = $label->value()->identity();
            if (isset($values[$identity])) {
                $this->violation('value_labels.duplicate_value', $labelPath . '.value', 'A value may have only one label in a replacement set.');
            }
            $values[$identity] = true;
        }
    }

    private function validateScalar(ScalarValue $value, string $path): void
    {
        if ($value->type() === 'number') {
            if (!is_finite($value->numberValue())) {
                $this->violation('value.non_finite', $path, 'Canonical numeric values must be finite binary64 values.');
            }

            return;
        }

        $this->validateText($value->stringValue(), $path);
    }

    private function validateVariableName(string $name, string $path): void
    {
        if ($name === '' || strlen($name) > 255 || str_contains($name, "\0") || preg_match('//u', $name) !== 1) {
            $this->violation(
                'variable.invalid_name',
                $path,
                'Variable names must contain 1-255 bytes of valid UTF-8 scalar text without NUL.',
            );
        }
    }

    private function validateText(?string $text, string $path): void
    {
        if ($text !== null && (str_contains($text, "\0") || preg_match('//u', $text) !== 1)) {
            $this->violation('text.invalid_unicode', $path, 'Text must contain valid UTF-8 scalar text without NUL.');
        }
    }

    /**
     * @param list<array{index: int, lower: ?float, upper: ?float}> $ranges
     * @param array<string, int> $exactValues
     */
    private function validateRangeAmbiguity(array $ranges, array $exactValues, RecodeOperation $operation, string $path): void
    {
        foreach ($ranges as $leftOffset => $left) {
            if (($left['lower'] !== null && !is_finite($left['lower'])) || ($left['upper'] !== null && !is_finite($left['upper']))) {
                continue;
            }
            foreach (array_slice($ranges, $leftOffset + 1) as $right) {
                if (($right['lower'] !== null && !is_finite($right['lower'])) || ($right['upper'] !== null && !is_finite($right['upper']))) {
                    continue;
                }
                if ($this->rangesOverlap($left, $right)) {
                    $this->violation(
                        'recode.ambiguous_mapping',
                        $path . '.rules[' . $right['index'] . '].selector',
                        'Inclusive numeric ranges must not overlap.',
                    );
                }
            }

            foreach ($exactValues as $identity => $exactIndex) {
                if (!str_starts_with($identity, 'number:')) {
                    continue;
                }
                $selector = $operation->rules()[$exactIndex]->selector();
                if ($selector instanceof ExactValueSelector && $this->rangeContains($left, $selector->value()->numberValue())) {
                    $this->violation(
                        'recode.ambiguous_mapping',
                        $path . '.rules[' . $exactIndex . '].selector',
                        'An exact numeric selector must not overlap a numeric range.',
                    );
                }
            }
        }
    }

    /**
     * @param array{lower: ?float, upper: ?float} $left
     * @param array{lower: ?float, upper: ?float} $right
     */
    private function rangesOverlap(array $left, array $right): bool
    {
        return ($left['upper'] === null || $right['lower'] === null || $left['upper'] >= $right['lower'])
            && ($right['upper'] === null || $left['lower'] === null || $right['upper'] >= $left['lower']);
    }

    /** @param array{lower: ?float, upper: ?float} $range */
    private function rangeContains(array $range, float $value): bool
    {
        return ($range['lower'] === null || $value >= $range['lower'])
            && ($range['upper'] === null || $value <= $range['upper']);
    }

    private function violation(string $code, string $path, string $message): void
    {
        $this->violations[] = new ValidationViolation($code, $path, $message);
    }
}
