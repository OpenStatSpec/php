<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

use OpenStatSpec\Frontend\Spss\Binding\BoundCompute;
use OpenStatSpec\Frontend\Spss\Binding\BoundConditionalAssign;
use OpenStatSpec\Frontend\Spss\Binding\BoundExecute;
use OpenStatSpec\Frontend\Spss\Binding\BoundFormat;
use OpenStatSpec\Frontend\Spss\Binding\BoundMeasurementLevel;
use OpenStatSpec\Frontend\Spss\Binding\BoundProgram;
use OpenStatSpec\Frontend\Spss\Binding\BoundRecode;
use OpenStatSpec\Frontend\Spss\Binding\BoundValueLabels;
use OpenStatSpec\Frontend\Spss\Binding\BoundVariableLabel;
use OpenStatSpec\Transformation\Plan\Operation;
use OpenStatSpec\Transformation\Plan\Operation\AssignOperation;
use OpenStatSpec\Transformation\Plan\Operation\ConditionalAssignOperation;
use OpenStatSpec\Transformation\Plan\Operation\ExecuteOperation;
use OpenStatSpec\Transformation\Plan\Operation\RecodeOperation;
use OpenStatSpec\Transformation\Plan\Operation\ReplaceValueLabelsOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetFormatOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetMeasurementLevelOperation;
use OpenStatSpec\Transformation\Plan\Operation\SetVariableLabelOperation;
use OpenStatSpec\Transformation\Plan\PlanContract;
use OpenStatSpec\Transformation\Plan\TransformationPlan;

/** Compiles only bound SPSS semantics into the source-neutral official plan. */
final class Compiler
{
    public function compile(BoundProgram $program): TransformationPlan
    {
        $operations = [];
        foreach ($program->statements as $statement) {
            $operations[] = match (true) {
                $statement instanceof BoundRecode => new RecodeOperation(
                    $statement->sourceVariable,
                    $statement->targetVariable,
                    $statement->targetMode,
                    $statement->rules,
                    $statement->unmatched,
                ),
                $statement instanceof BoundCompute => new AssignOperation(
                    $statement->target,
                    $statement->targetMode,
                    $statement->value,
                ),
                $statement instanceof BoundConditionalAssign => new ConditionalAssignOperation(
                    $statement->condition,
                    $statement->target,
                    $statement->value,
                ),
                $statement instanceof BoundVariableLabel => new SetVariableLabelOperation(
                    $statement->variable,
                    $statement->label,
                ),
                $statement instanceof BoundValueLabels => new ReplaceValueLabelsOperation(
                    $statement->variable,
                    $statement->labels,
                ),
                $statement instanceof BoundFormat => new SetFormatOperation(
                    $statement->variable,
                    $statement->family,
                    $statement->width,
                    $statement->decimals,
                ),
                $statement instanceof BoundMeasurementLevel => new SetMeasurementLevelOperation(
                    $statement->variable,
                    $statement->level,
                ),
                $statement instanceof BoundExecute => new ExecuteOperation(),
                default => throw new \LogicException(sprintf(
                    'Unsupported bound SPSS statement %s.',
                    $statement::class,
                )),
            };
        }

        $contract = array_any(
            $operations,
            static fn(Operation $operation): bool => $operation->minimumContract() === PlanContract::V02,
        ) ? PlanContract::V02 : PlanContract::V01;

        return new TransformationPlan($contract, $program->inputAlias, $operations);
    }
}
