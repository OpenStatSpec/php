<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

use OpenStatSpec\Frontend\Spss\Ast\BooleanPredicate;
use OpenStatSpec\Frontend\Spss\Ast\Comparison;
use OpenStatSpec\Frontend\Spss\Ast\ComputeStatement;
use OpenStatSpec\Frontend\Spss\Ast\ElseInput;
use OpenStatSpec\Frontend\Spss\Ast\ExecuteStatement;
use OpenStatSpec\Frontend\Spss\Ast\ExpressionOperand;
use OpenStatSpec\Frontend\Spss\Ast\FormatsStatement;
use OpenStatSpec\Frontend\Spss\Ast\FormatTarget;
use OpenStatSpec\Frontend\Spss\Ast\IfStatement;
use OpenStatSpec\Frontend\Spss\Ast\LiteralOperand;
use OpenStatSpec\Frontend\Spss\Ast\MissingInput;
use OpenStatSpec\Frontend\Spss\Ast\NotPredicate;
use OpenStatSpec\Frontend\Spss\Ast\VariableRange;
use OpenStatSpec\Frontend\Spss\Ast\Program;
use OpenStatSpec\Frontend\Spss\Ast\Predicate;
use OpenStatSpec\Frontend\Spss\Ast\RangeInput;
use OpenStatSpec\Frontend\Spss\Ast\RecodeInput;
use OpenStatSpec\Frontend\Spss\Ast\RecodeOutput;
use OpenStatSpec\Frontend\Spss\Ast\RecodeOutputKind;
use OpenStatSpec\Frontend\Spss\Ast\RecodeRule;
use OpenStatSpec\Frontend\Spss\Ast\RecodeStatement;
use OpenStatSpec\Frontend\Spss\Ast\ScalarValue;
use OpenStatSpec\Frontend\Spss\Ast\SystemMissingInput;
use OpenStatSpec\Frontend\Spss\Ast\ValueInput;
use OpenStatSpec\Frontend\Spss\Ast\ValueLabel;
use OpenStatSpec\Frontend\Spss\Ast\ValueLabelGroup;
use OpenStatSpec\Frontend\Spss\Ast\ValueLabelsStatement;
use OpenStatSpec\Frontend\Spss\Ast\VariableLabelsStatement;
use OpenStatSpec\Frontend\Spss\Ast\VariableLabelAssignment;
use OpenStatSpec\Frontend\Spss\Ast\VariableLevelGroup;
use OpenStatSpec\Frontend\Spss\Ast\VariableLevelStatement;
use OpenStatSpec\Frontend\Spss\Ast\VariableOperand;
use OpenStatSpec\Frontend\Spss\Number\DecimalBinary64;
use OpenStatSpec\Transformation\Diagnostic\SourceSpan;
use OpenStatSpec\Transformation\Diagnostic\TransformationDiagnostic;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;

final class Parser
{
    /** @var list<Token> */
    private array $tokens = [];
    private int $position = 0;
    private bool $officialV03 = false;

    public function __construct(private readonly Lexer $lexer = new Lexer()) {}

    public function parse(string $source, bool $officialV03 = false): Program
    {
        return $this->parseTokens($this->lexer->tokenize($source, $officialV03), $officialV03);
    }

    /** @param list<Token> $tokens */
    public function parseTokens(array $tokens, bool $officialV03 = false): Program
    {
        $this->officialV03 = $officialV03;
        $this->tokens = $tokens;
        $this->position = 0;
        $statements = [];

        while (!$this->check(TokenType::EndOfFile)) {
            $command = $this->current();
            if ($this->matchKeyword('RECODE')) {
                $statements[] = $this->recode($command);
                while ($this->officialV03 && $this->match(TokenType::Slash)) {
                    $statements[] = $this->recode($this->current());
                }
            } elseif ($this->matchKeyword('VARIABLE')) {
                if ($this->matchKeyword('LABELS')) {
                    $statements[] = $this->variableLabels($command);
                } elseif ($this->matchKeyword('LEVEL')) {
                    $statements[] = $this->variableLevel($command);
                } else {
                    $this->fail($this->current(), 'Expected LABELS or LEVEL after VARIABLE.');
                }
            } elseif ($this->officialV03 && $this->matchKeyword('ADD')) {
                $this->consumeKeyword('VALUE', 'Expected VALUE after ADD.');
                $this->consumeKeyword('LABELS', 'Expected LABELS after ADD VALUE.');
                $statements[] = $this->valueLabels($command, true);
            } elseif ($this->matchKeyword('VALUE')) {
                $this->consumeKeyword('LABELS', 'Expected LABELS after VALUE.');
                $statements[] = $this->valueLabels($command);
            } elseif ($this->matchKeyword('VAR')) {
                $this->consumeKeyword('LAB', 'Expected LAB after VAR.');
                $statements[] = $this->variableLabels($command);
            } elseif ($this->matchKeyword('VAL')) {
                $this->consumeKeyword('LAB', 'Expected LAB after VAL.');
                $statements[] = $this->valueLabels($command);
            } elseif ($this->matchKeyword('EXECUTE')) {
                $statements[] = new ExecuteStatement($command->line, $command->span);
            } elseif ($this->matchKeyword('COMPUTE')) {
                $statements[] = $this->compute($command);
            } elseif ($this->matchKeyword('IF')) {
                $statements[] = $this->ifStatement($command);
            } elseif ($this->matchKeyword('FORMATS')) {
                $statements[] = $this->formats($command);
            } else {
                $code = $command->isWord()
                    ? 'unsupported_spss_command'
                    : 'spss_syntax_error';
                $this->fail($command, sprintf(
                    'Unsupported SPSS command %s.',
                    $command->lexeme === '' ? '<end of input>' : $command->lexeme,
                ), $code);
            }

            $this->consume(TokenType::Terminator, 'Expected a period after the SPSS command.');
        }

        return new Program($statements, $this->officialV03);
    }

    private function compute(Token $command): ComputeStatement
    {
        $target = $this->consumeIdentifier('Expected a target variable after COMPUTE.');
        $this->consume(TokenType::Equals, 'Expected = after the COMPUTE target.');
        $expression = $this->expressionOperand('Expected a numeric operand after =.');
        $this->rejectUnsupportedExpressionContinuation();

        return new ComputeStatement(
            $target->lexeme,
            $target->span,
            $expression,
            SourceSpan::cover($command->span, $expression->span()),
        );
    }

    private function ifStatement(Token $command): IfStatement
    {
        $this->consume(TokenType::LeftParenthesis, 'IF requires parentheses around the complete predicate.');
        $predicate = $this->parsePredicate();
        $this->consume(TokenType::RightParenthesis, 'Expected ) after the complete IF predicate.');
        $target = $this->consumeIdentifier('Expected an existing target variable after the IF predicate.');
        $this->consume(TokenType::Equals, 'Expected = after the IF target.');
        $expression = $this->expressionOperand('Expected a numeric operand after =.');
        $this->rejectUnsupportedExpressionContinuation();

        return new IfStatement(
            $predicate,
            $target->lexeme,
            $target->span,
            $expression,
            SourceSpan::cover($command->span, $expression->span()),
        );
    }

    private function parsePredicate(): Predicate
    {
        return $this->parseOr();
    }

    private function parseOr(): Predicate
    {
        $predicate = $this->parseAnd();
        while ($this->matchKeyword('OR')) {
            $predicate = $this->merge('or', $predicate, $this->parseAnd());
        }

        return $predicate;
    }

    private function parseAnd(): Predicate
    {
        $predicate = $this->parseNot();
        while ($this->matchKeyword('AND')) {
            $predicate = $this->merge('and', $predicate, $this->parseNot());
        }

        return $predicate;
    }

    private function parseNot(): Predicate
    {
        if ($this->officialV03 && $this->matchKeyword('NOT')) {
            $start = $this->previous();
            $operand = $this->parseNot();
            return new NotPredicate($operand, SourceSpan::cover($start->span, $operand->span()));
        }
        return $this->comparison();
    }

    private function comparison(): Predicate
    {
        if ($this->match(TokenType::LeftParenthesis)) {
            $predicate = $this->parsePredicate();
            $this->consume(TokenType::RightParenthesis, 'Expected ) after the parenthesized predicate.');

            return $predicate;
        }
        if ($this->current()->isKeyword('NOT')) {
            $this->fail($this->current(), 'NOT is not supported in numeric predicates.', 'expression_type_unsupported');
        }

        $left = $this->expressionOperand('Expected a numeric comparison operand.');
        $operator = $this->comparisonOperator();
        $right = $this->expressionOperand('Expected a numeric comparison operand after the operator.');
        $this->rejectUnsupportedExpressionContinuation();

        return new Comparison($left, $operator->lexeme, $right, SourceSpan::cover($left->span(), $right->span()));
    }

    private function comparisonOperator(): Token
    {
        if ($this->isArithmeticContinuation()) {
            $this->fail(
                $this->current(),
                'Arithmetic expressions are not supported.',
                'expression_type_unsupported',
            );
        }
        if ($this->officialV03 && ($this->match(TokenType::NotEqual) || $this->matchKeyword('NE'))) {
            return $this->previous();
        }
        foreach ([
            TokenType::Equals,
            TokenType::LessThan,
            TokenType::LessThanOrEqual,
            TokenType::GreaterThan,
            TokenType::GreaterThanOrEqual,
        ] as $type) {
            if ($this->match($type)) {
                return $this->previous();
            }
        }

        $this->fail(
            $this->current(),
            'Expected a supported comparison operator.',
            $this->officialV03 && $this->check(TokenType::RightParenthesis) ? 'expression_type_unsupported' : 'spss_syntax_error',
        );
    }

    private function expressionOperand(string $message): ExpressionOperand
    {
        if ($this->match(TokenType::String)) {
            $this->fail($this->previous(), 'String expressions are not supported.', 'expression_type_unsupported');
        }
        if ($this->current()->isKeyword('NAN') || $this->current()->isKeyword('INFINITY')) {
            $this->fail($this->current(), 'NaN and infinity are not finite numeric operands.');
        }
        if ($this->match(TokenType::Number)) {
            $token = $this->previous();
            try {
                DecimalBinary64::bits($token->lexeme);
            } catch (TransformationFailure $failure) {
                $this->fail($token, $failure->getMessage());
            }

            return new LiteralOperand($token->lexeme, $token->span);
        }
        if ($this->current()->isWord()) {
            $token = $this->advance();

            return new VariableOperand($token->lexeme, $token->span);
        }

        $this->fail($this->current(), $message);
    }

    private function rejectUnsupportedExpressionContinuation(): void
    {
        if ($this->isArithmeticContinuation()) {
            $this->fail(
                $this->current(),
                'Arithmetic expressions are not supported.',
                'expression_type_unsupported',
            );
        }
    }

    private function merge(string $operator, Predicate $left, Predicate $right): BooleanPredicate
    {
        $operands = $left instanceof BooleanPredicate && $left->operator === $operator
            ? $left->operands
            : [$left];
        array_push(
            $operands,
            ...($right instanceof BooleanPredicate && $right->operator === $operator ? $right->operands : [$right]),
        );

        return new BooleanPredicate($operator, $operands, SourceSpan::cover($left->span(), $right->span()));
    }

    private function isArithmeticContinuation(): bool
    {
        if ($this->check(TokenType::ArithmeticOperator) || $this->check(TokenType::Slash)) {
            return true;
        }

        return $this->check(TokenType::Number)
            && ($this->current()->lexeme[0] === '+' || $this->current()->lexeme[0] === '-');
    }

    private function formats(Token $command): FormatsStatement
    {
        $targets = [];
        do {
            [$variables, $spans] = $this->existingVariables('FORMATS requires variables and a format.', $this->officialV03);
            $this->consume(TokenType::LeftParenthesis, 'Expected ( before the format.');
            $familyAndWidth = $this->consumeIdentifier('Expected an F format such as F8.2.');
            if (preg_match('/\AF([1-9][0-9]*)\z/iD', $familyAndWidth->lexeme, $matches) !== 1) {
                $this->fail($familyAndWidth, 'Only the numeric F format family is supported.', 'invalid_format');
            }
            $decimalToken = $this->consume(TokenType::Number, 'F format requires . followed by decimals.');
            if (
                $familyAndWidth->span->endOffset !== $decimalToken->span->startOffset
                || preg_match('/\A\.([0-9]+)\z/D', $decimalToken->lexeme, $decimalMatches) !== 1
            ) {
                $this->fail($decimalToken, 'F format must use the Fwidth.decimals form.', 'invalid_format');
            }
            $rightParenthesis = $this->consume(TokenType::RightParenthesis, 'Expected ) after the format.');
            foreach ($variables as $index => $variable) {
                $targets[] = new FormatTarget(
                    $variable,
                    'F',
                    (int) $matches[1],
                    (int) $decimalMatches[1],
                    $spans[$index],
                    SourceSpan::cover($spans[$index], $rightParenthesis->span),
                );
            }
        } while (($this->officialV03 && $this->match(TokenType::Slash)) || $this->current()->isWord());

        return new FormatsStatement(
            $targets,
            SourceSpan::cover($command->span, $targets[array_key_last($targets)]->span),
        );
    }

    private function variableLevel(Token $command): VariableLevelStatement
    {
        $groups = [];
        do {
            [$variables, $variableSpans] = $this->existingVariables('VARIABLE LEVEL requires at least one variable.');
            $this->consume(TokenType::LeftParenthesis, 'Expected ( before the measurement level.');
            $level = $this->current();
            if (
                !$this->matchKeyword('NOMINAL')
                && !$this->matchKeyword('ORDINAL')
                && !$this->matchKeyword('SCALE')
            ) {
                $this->fail($level, 'Expected NOMINAL, ORDINAL, or SCALE.');
            }
            $rightParenthesis = $this->consume(TokenType::RightParenthesis, 'Expected ) after the measurement level.');
            $groups[] = new VariableLevelGroup(
                $variables,
                $variableSpans,
                strtolower($level->lexeme),
                SourceSpan::cover($variableSpans[0], $rightParenthesis->span),
            );
        } while ($this->match(TokenType::Slash));

        return new VariableLevelStatement(
            $groups,
            SourceSpan::cover($command->span, $groups[array_key_last($groups)]->span),
        );
    }

    private function recode(Token $command): RecodeStatement
    {
        [$sources, $sourceSpans] = $this->existingVariables('Expected a source variable after RECODE.', stopAtInto: true);

        $rules = [];
        while ($this->match(TokenType::LeftParenthesis)) {
            $leftParenthesis = $this->previous();
            $input = $this->recodeInput();
            if ($this->check(TokenType::Comma)) {
                if (!$input instanceof ValueInput) {
                    $this->fail($this->current(), 'Comma-separated RECODE selectors must all be typed literals.');
                }
                $values = [$input->value];
                while ($this->match(TokenType::Comma)) {
                    $comma = $this->previous();
                    $additionalInput = $this->recodeInput();
                    if (!$additionalInput instanceof ValueInput) {
                        $this->fail($comma, 'Comma-separated RECODE selectors must all be typed literals.');
                    }
                    $values[] = $additionalInput->value;
                }
                $input = new ValueInput(...$values);
            }
            $this->consume(TokenType::Equals, 'Expected = in a RECODE rule.');
            $output = $this->recodeOutput();
            $rightParenthesis = $this->consume(TokenType::RightParenthesis, 'Expected ) after a RECODE rule.');
            $rules[] = new RecodeRule($input, $output, SourceSpan::cover($leftParenthesis->span, $rightParenthesis->span));
        }
        if ($rules === []) {
            $this->fail($this->current(), 'RECODE requires at least one parenthesized rule.');
        }

        $targets = [];
        $targetSpans = [];
        if ($this->matchKeyword('INTO')) {
            do {
                if ($this->officialV03 && $this->current()->isKeyword('TO')) {
                    $this->fail($this->current(), 'TO is forbidden in generated INTO targets.');
                }
                $target = $this->consumeIdentifier('Expected a target variable after INTO.');
                $targets[] = $target->lexeme;
                $targetSpans[] = $target->span;
            } while ($this->current()->isWord());
        }

        return new RecodeStatement(
            $command->line,
            $sources,
            $sourceSpans,
            $rules,
            $targets,
            $targetSpans,
            SourceSpan::cover($command->span, $this->previous()->span),
        );
    }

    private function recodeInput(): RecodeInput
    {
        if ($this->matchKeyword('SYSMIS')) {
            return new SystemMissingInput($this->previous()->span);
        }
        if ($this->matchKeyword('MISSING')) {
            return new MissingInput($this->previous()->span);
        }
        if ($this->matchKeyword('ELSE')) {
            return new ElseInput($this->previous()->span);
        }
        if ($this->matchKeyword('LOWEST')) {
            $lowest = $this->previous();
            $this->consumeKeyword('THRU', 'LOWEST must be followed by THRU.');
            if ($this->matchKeyword('HIGHEST')) {
                return new RangeInput(null, null, SourceSpan::cover($lowest->span, $this->previous()->span));
            }
            $upper = $this->scalar('Expected an upper bound after LOWEST THRU.');

            return new RangeInput(null, $upper, SourceSpan::cover($lowest->span, $upper->span));
        }

        $lower = $this->scalar('Expected a value or selector in a RECODE rule.');
        if (!$this->matchKeyword('THRU')) {
            return new ValueInput($lower);
        }
        if ($this->matchKeyword('HIGHEST')) {
            return new RangeInput($lower, null, SourceSpan::cover($lower->span, $this->previous()->span));
        }
        $upper = $this->scalar('Expected an upper bound after THRU.');

        return new RangeInput($lower, $upper, SourceSpan::cover($lower->span, $upper->span));
    }

    private function recodeOutput(): RecodeOutput
    {
        if ($this->matchKeyword('COPY')) {
            return new RecodeOutput(RecodeOutputKind::Copy, $this->previous()->span);
        }
        if ($this->matchKeyword('SYSMIS')) {
            return new RecodeOutput(RecodeOutputKind::SystemMissing, $this->previous()->span);
        }

        $value = $this->scalar('Expected a value, COPY, or SYSMIS after =.');

        return new RecodeOutput(RecodeOutputKind::Value, $value->span, $value);
    }

    private function variableLabels(Token $command): VariableLabelsStatement
    {
        $labels = [];
        $assignments = [];
        while (!$this->check(TokenType::Terminator) && !$this->check(TokenType::EndOfFile)) {
            [$variables, $spans] = $this->existingVariables('Expected a variable name in VARIABLE LABELS.', $this->officialV03);
            $label = $this->consume(TokenType::String, 'Expected a quoted variable label.');
            foreach ($variables as $index => $variable) {
                $labels[is_string($variable) ? $variable : $variable->last] = $label->lexeme;
                $assignments[] = new VariableLabelAssignment(
                    $variable,
                    $label->lexeme,
                    $spans[$index],
                    SourceSpan::cover($spans[$index], $label->span),
                );
            }
            if ($this->officialV03 && $this->match(TokenType::Slash) && !$this->current()->isWord()) {
                $this->fail($this->current(), 'Expected another variable label group.');
            }
        }
        if ($labels === []) {
            $this->fail($this->current(), 'VARIABLE LABELS requires at least one variable and label.');
        }

        return new VariableLabelsStatement(
            $command->line,
            $labels,
            $assignments,
            SourceSpan::cover($command->span, $assignments[array_key_last($assignments)]->span),
        );
    }

    private function valueLabels(Token $command, bool $add = false): ValueLabelsStatement
    {
        $groups = [];
        do {
            [$variables, $variableSpans] = $this->existingVariables('VALUE LABELS requires at least one variable before its value-label pairs.');

            $labels = [];
            while (!$this->check(TokenType::Slash) && !$this->check(TokenType::Terminator) && !$this->check(TokenType::EndOfFile)) {
                $value = $this->scalar('Expected a value in VALUE LABELS.');
                $label = $this->consume(TokenType::String, 'Expected a quoted label after the value.');
                $labels[] = new ValueLabel($value, $label->lexeme, SourceSpan::cover($value->span, $label->span));
            }
            if ($labels === []) {
                $this->fail($this->current(), 'VALUE LABELS requires at least one value-label pair.');
            }
            $groups[] = new ValueLabelGroup(
                $variables,
                $variableSpans,
                $labels,
                SourceSpan::cover($variableSpans[0], $labels[array_key_last($labels)]->span),
            );
        } while ($this->match(TokenType::Slash));

        return new ValueLabelsStatement(
            $command->line,
            $groups,
            SourceSpan::cover($command->span, $groups[array_key_last($groups)]->span),
            $add,
        );
    }

    /** @return array{non-empty-list<string|VariableRange>, non-empty-list<SourceSpan>} */
    private function existingVariables(string $message, bool $multiple = true, bool $stopAtInto = false): array
    {
        $first = $this->consumeIdentifier($message);
        if ($this->officialV03 && $first->isKeyword('TO')) {
            $this->fail($first, 'TO requires a preceding variable.');
        }
        $variables = [$first->lexeme];
        $spans = [$first->span];
        while ($multiple && $this->current()->isWord() && !($stopAtInto && $this->current()->isKeyword('INTO'))) {
            if ($this->officialV03 && $this->matchKeyword('TO')) {
                $last = $this->consumeIdentifier('TO requires an endpoint.');
                if ($last->isKeyword('TO')) {
                    $this->fail($last, 'TO requires an endpoint.');
                }
                $index = array_key_last($variables);
                $spans[$index] = SourceSpan::cover($spans[$index], $last->span);
                $variables[$index] = new VariableRange($variables[$index], $last->lexeme, $spans[$index]);
            } else {
                $variable = $this->advance();
                $variables[] = $variable->lexeme;
                $spans[] = $variable->span;
            }
        }
        return [$variables, array_values($spans)];
    }

    private function scalar(string $message): ScalarValue
    {
        if ($this->match(TokenType::String)) {
            $token = $this->previous();

            return new ScalarValue($token->lexeme, null, $token->span);
        }
        if (!$this->match(TokenType::Number)) {
            $this->fail($this->current(), $message);
        }
        $lexeme = $this->previous()->lexeme;
        try {
            DecimalBinary64::bits($lexeme);
        } catch (TransformationFailure $failure) {
            $this->fail($this->previous(), $failure->getMessage());
        }

        return new ScalarValue($lexeme, $lexeme, $this->previous()->span);
    }

    private function consumeIdentifier(string $message): Token
    {
        if (!$this->current()->isWord()) {
            $this->fail($this->current(), $message);
        }

        return $this->advance();
    }

    private function consumeKeyword(string $keyword, string $message): Token
    {
        if (!$this->current()->isKeyword($keyword)) {
            $this->fail($this->current(), $message);
        }

        return $this->advance();
    }

    private function consume(TokenType $type, string $message): Token
    {
        if (!$this->check($type)) {
            $this->fail($this->current(), $message);
        }

        return $this->advance();
    }

    private function match(TokenType $type): bool
    {
        if (!$this->check($type)) {
            return false;
        }
        $this->advance();

        return true;
    }

    private function matchKeyword(string $keyword): bool
    {
        if (!$this->current()->isKeyword($keyword)) {
            return false;
        }
        $this->advance();

        return true;
    }

    private function check(TokenType $type): bool
    {
        return $this->current()->type === $type;
    }

    private function advance(): Token
    {
        return $this->tokens[$this->position++];
    }

    private function current(): Token
    {
        return $this->tokens[$this->position];
    }

    private function previous(): Token
    {
        return $this->tokens[$this->position - 1];
    }

    private function fail(Token $token, string $message, string $code = 'spss_syntax_error'): never
    {
        throw new SpssSyntaxException([
            new TransformationDiagnostic($code, '$.source_text', $message, $token->span),
        ]);
    }
}
