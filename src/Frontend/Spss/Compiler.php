<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

use LogicException;
use OpenStatSpec\Frontend\Spss\Ast\ElseInput;
use OpenStatSpec\Frontend\Spss\Ast\RangeInput;
use OpenStatSpec\Frontend\Spss\Ast\RecodeInput;
use OpenStatSpec\Frontend\Spss\Ast\RecodeOutput;
use OpenStatSpec\Frontend\Spss\Ast\RecodeOutputKind;
use OpenStatSpec\Frontend\Spss\Ast\ScalarValue as AstScalarValue;
use OpenStatSpec\Frontend\Spss\Ast\SystemMissingInput;
use OpenStatSpec\Frontend\Spss\Ast\ValueInput;
use OpenStatSpec\Frontend\Spss\Ast\ValueLabel as AstValueLabel;
use OpenStatSpec\Frontend\Spss\Binding\BoundProgram;
use OpenStatSpec\Frontend\Spss\Binding\BoundRecode;
use OpenStatSpec\Frontend\Spss\Binding\BoundValueLabels;
use OpenStatSpec\Frontend\Spss\Binding\BoundVariableLabel;
use OpenStatSpec\Transformation\Model\Action\AssignValueAction;
use OpenStatSpec\Transformation\Model\Action\CopySourceAction;
use OpenStatSpec\Transformation\Model\Action\SetMissingAction;
use OpenStatSpec\Transformation\Model\RecodeAction;
use OpenStatSpec\Transformation\Model\RecodeOperation;
use OpenStatSpec\Transformation\Model\RecodeRule;
use OpenStatSpec\Transformation\Model\RecodeSelector;
use OpenStatSpec\Transformation\Model\ScalarValue;
use OpenStatSpec\Transformation\Model\Selector\ElseSelector;
use OpenStatSpec\Transformation\Model\Selector\ExactValueSelector;
use OpenStatSpec\Transformation\Model\Selector\MissingValueSelector;
use OpenStatSpec\Transformation\Model\Selector\NumericRangeSelector;
use OpenStatSpec\Transformation\Model\SetValueLabelsOperation;
use OpenStatSpec\Transformation\Model\SetVariableLabelOperation;
use OpenStatSpec\Transformation\Model\TransformationPlan;
use OpenStatSpec\Transformation\Model\ValueLabel;
use OpenStatSpec\Transformation\Validation\PlanValidator;

/** Compiles only bound SPSS semantics into the source-neutral canonical model. */
final class Compiler
{
    public function __construct(private readonly PlanValidator $validator = new PlanValidator()) {}

    public function compile(BoundProgram $program): TransformationPlan
    {
        $operations = [];
        foreach ($program->statements as $statement) {
            if ($statement instanceof BoundRecode) {
                $rules = [];
                $hasElse = false;
                foreach ($statement->rules as $rule) {
                    $selector = $this->selector($rule->input);
                    $hasElse = $hasElse || $selector instanceof ElseSelector;
                    $rules[] = new RecodeRule($selector, $this->action($rule->output));
                }
                if (!$hasElse) {
                    $defaultAction = $statement->sourceVariable === $statement->targetVariable
                        ? new CopySourceAction()
                        : new SetMissingAction();
                    $rules[] = new RecodeRule(new ElseSelector(), $defaultAction);
                }
                $operations[] = new RecodeOperation($statement->sourceVariable, $statement->targetVariable, $rules);
                continue;
            }
            if ($statement instanceof BoundVariableLabel) {
                $operations[] = new SetVariableLabelOperation($statement->variable, $statement->label);
                continue;
            }
            if ($statement instanceof BoundValueLabels) {
                $labels = array_map(
                    fn(AstValueLabel $label): ValueLabel => new ValueLabel(
                        $this->scalar($label->value),
                        $label->label,
                    ),
                    $statement->labels,
                );
                $operations[] = new SetValueLabelsOperation($statement->variable, $labels);
                continue;
            }

            throw new LogicException(sprintf('Unsupported bound SPSS statement %s.', $statement::class));
        }

        $plan = new TransformationPlan($program->datasetId, $operations);
        $this->validator->assertValid($plan);

        return $plan;
    }

    private function selector(RecodeInput $input): RecodeSelector
    {
        return match (true) {
            $input instanceof ValueInput => new ExactValueSelector($this->scalar($input->value)),
            $input instanceof RangeInput => new NumericRangeSelector(
                $input->lower === null ? null : (float) $input->lower->value,
                $input->upper === null ? null : (float) $input->upper->value,
            ),
            $input instanceof SystemMissingInput => new MissingValueSelector(),
            $input instanceof ElseInput => new ElseSelector(),
            default => throw new LogicException(sprintf('Unsupported SPSS recode input %s.', $input::class)),
        };
    }

    private function action(RecodeOutput $output): RecodeAction
    {
        return match ($output->kind) {
            RecodeOutputKind::Value => new AssignValueAction($this->scalar(
                $output->value ?? throw new LogicException('A value output must contain a scalar.'),
            )),
            RecodeOutputKind::Copy => new CopySourceAction(),
            RecodeOutputKind::SystemMissing => new SetMissingAction(),
        };
    }

    private function scalar(AstScalarValue $value): ScalarValue
    {
        return is_string($value->value) ? ScalarValue::string($value->value) : ScalarValue::number($value->value);
    }
}
