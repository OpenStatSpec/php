<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

use OpenStatSpec\Frontend\Spss\Ast\ElseInput;
use OpenStatSpec\Frontend\Spss\Ast\DeleteVariablesStatement;
use OpenStatSpec\Frontend\Spss\Ast\ExecuteStatement;
use OpenStatSpec\Frontend\Spss\Ast\MissingInput;
use OpenStatSpec\Frontend\Spss\Ast\StringStatement;
use OpenStatSpec\Frontend\Spss\Ast\Program;
use OpenStatSpec\Frontend\Spss\Ast\RangeInput;
use OpenStatSpec\Frontend\Spss\Ast\RecodeStatement;
use OpenStatSpec\Frontend\Spss\Ast\ValueLabelsStatement;
use OpenStatSpec\Frontend\Spss\Ast\VariableLabelsStatement;
use OpenStatSpec\Frontend\Spss\Binding\BoundProgram;
use OpenStatSpec\Frontend\Spss\Binding\BoundRecode;
use OpenStatSpec\Frontend\Spss\Binding\BoundValueLabels;
use OpenStatSpec\Frontend\Spss\Binding\BoundCreateVariable;
use OpenStatSpec\Frontend\Spss\Binding\BoundDeleteVariable;
use OpenStatSpec\Frontend\Spss\Binding\BoundVariableLabel;

final class Binder
{
    public function bind(string $datasetId, Program $program): BoundProgram
    {
        if (trim($datasetId) === '') {
            throw new SpssSyntaxException([new Diagnostic(1, 1, 'Dataset id must not be empty.')]);
        }

        $bound = [];
        foreach ($program->statements as $statement) {
            if ($statement instanceof ExecuteStatement) {
                continue;
            }
            if ($statement instanceof StringStatement) {
                foreach ($statement->variables as $variable) {
                    $bound[] = new BoundCreateVariable($variable, 'string', $statement->width);
                }
                continue;
            }
            if ($statement instanceof DeleteVariablesStatement) {
                foreach ($statement->variables as $variable) {
                    $bound[] = new BoundDeleteVariable($variable);
                }
                continue;
            }
            if ($statement instanceof RecodeStatement) {
                $targets = $statement->targets === [] ? $statement->sources : $statement->targets;
                if (count($statement->sources) !== count($targets)) {
                    $this->fail($statement->line(), 'RECODE INTO must have exactly one target for each source variable.');
                }
                if ($statement->targets !== [] && count(array_unique($targets)) !== count($targets)) {
                    $this->fail(
                        $statement->line(),
                        'RECODE INTO must not contain duplicate target variables in the same statement.',
                    );
                }
                foreach ($targets as $targetIndex => $target) {
                    foreach (array_slice($statement->sources, $targetIndex + 1) as $laterSource) {
                        if ($target === $laterSource) {
                            $this->fail(
                                $statement->line(),
                                'RECODE INTO targets must not overwrite a source used by a later pair in the same statement.',
                            );
                        }
                    }
                }
                $elseSeen = false;
                foreach ($statement->rules as $index => $rule) {
                    if ($rule->input instanceof MissingInput) {
                        $this->fail(
                            $statement->line(),
                            'MISSING includes user-missing values, which require dataset metadata; use SYSMIS or explicit values.',
                        );
                    }
                    if ($rule->input instanceof ElseInput) {
                        if ($elseSeen || $index !== array_key_last($statement->rules)) {
                            $this->fail($statement->line(), 'ELSE may occur only once and must be the final RECODE rule.');
                        }
                        $elseSeen = true;
                    }
                    if ($rule->input instanceof RangeInput) {
                        $lower = $rule->input->lower?->value;
                        $upper = $rule->input->upper?->value;
                        if ($lower === null && $upper === null) {
                            $this->fail($statement->line(), 'LOWEST THRU HIGHEST is not a finite canonical range; use ELSE instead.');
                        }
                        if (is_string($lower) || is_string($upper)) {
                            $this->fail($statement->line(), 'RECODE ranges must use numeric bounds.');
                        }
                        if ($lower !== null && $upper !== null && $lower > $upper) {
                            $this->fail($statement->line(), 'RECODE range lower bound must not exceed its upper bound.');
                        }
                    }
                }
                foreach ($statement->sources as $index => $source) {
                    $bound[] = new BoundRecode($source, $targets[$index], $statement->rules);
                }
                continue;
            }
            if ($statement instanceof VariableLabelsStatement) {
                foreach ($statement->labels as $variable => $label) {
                    $bound[] = new BoundVariableLabel($variable, $label);
                }
                continue;
            }
            if ($statement instanceof ValueLabelsStatement) {
                foreach ($statement->groups as $group) {
                    foreach ($group->variables as $variable) {
                        $bound[] = new BoundValueLabels($variable, $group->labels);
                    }
                }
                continue;
            }

            $this->fail($statement->line(), sprintf('Unsupported AST statement %s.', $statement::class));
        }

        return new BoundProgram($datasetId, $bound);
    }

    private function fail(int $line, string $message): never
    {
        throw new SpssSyntaxException([new Diagnostic($line, 1, $message)]);
    }
}
