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

    public function __construct(private readonly Lexer $lexer = new Lexer()) {}

    public function parse(string $source): Program
    {
        return $this->parseTokens($this->lexer->tokenize($source));
    }

    /** @param list<Token> $tokens */
    public function parseTokens(array $tokens): Program
    {
        $this->tokens = $tokens;
        $this->position = 0;
        $statements = [];

        while (!$this->check(TokenType::EndOfFile)) {
            $command = $this->current();
            if ($this->matchKeyword('RECODE')) {
                $statements[] = $this->recode($command);
            } elseif ($this->matchKeyword('VARIABLE')) {
                if ($this->matchKeyword('LABELS')) {
                    $statements[] = $this->variableLabels($command);
                } elseif ($this->matchKeyword('LEVEL')) {
                    $statements[] = $this->variableLevel($command);
                } else {
                    $this->fail($this->current(), 'Expected LABELS or LEVEL after VARIABLE.');
                }
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

        return new Program($statements);
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
        $predicate = $this->comparison();
        while ($this->matchKeyword('AND')) {
            $predicate = $this->merge('and', $predicate, $this->comparison());
        }

        return $predicate;
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

        $this->fail($this->current(), 'Expected a supported comparison operator.');
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
            $variable = $this->consumeIdentifier('FORMATS requires a variable and format pair.');
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
            $targets[] = new FormatTarget(
                $variable->lexeme,
                'F',
                (int) $matches[1],
                (int) $decimalMatches[1],
                $variable->span,
                SourceSpan::cover($variable->span, $rightParenthesis->span),
            );
        } while ($this->current()->isWord());

        return new FormatsStatement(
            $targets,
            SourceSpan::cover($command->span, $targets[array_key_last($targets)]->span),
        );
    }

    private function variableLevel(Token $command): VariableLevelStatement
    {
        $groups = [];
        do {
            $variables = [];
            $variableSpans = [];
            $first = $this->consumeIdentifier('VARIABLE LEVEL requires at least one variable.');
            $variables[] = $first->lexeme;
            $variableSpans[] = $first->span;
            while ($this->current()->isWord()) {
                $variable = $this->advance();
                $variables[] = $variable->lexeme;
                $variableSpans[] = $variable->span;
            }
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
                SourceSpan::cover($first->span, $rightParenthesis->span),
            );
        } while ($this->match(TokenType::Slash));

        return new VariableLevelStatement(
            $groups,
            SourceSpan::cover($command->span, $groups[array_key_last($groups)]->span),
        );
    }

    private function recode(Token $command): RecodeStatement
    {
        $sources = [];
        $sourceSpans = [];
        do {
            $source = $this->consumeIdentifier('Expected a source variable after RECODE.');
            $sources[] = $source->lexeme;
            $sourceSpans[] = $source->span;
        } while ($this->current()->isWord() && !$this->current()->isKeyword('INTO'));

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
            $variable = $this->consumeIdentifier('Expected a variable name in VARIABLE LABELS.');
            $label = $this->consume(TokenType::String, 'Expected a quoted variable label.');
            $labels[$variable->lexeme] = $label->lexeme;
            $assignments[] = new VariableLabelAssignment(
                $variable->lexeme,
                $label->lexeme,
                $variable->span,
                SourceSpan::cover($variable->span, $label->span),
            );
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

    private function valueLabels(Token $command): ValueLabelsStatement
    {
        $groups = [];
        do {
            $variables = [];
            $variableSpans = [];
            while ($this->current()->isWord()) {
                $variable = $this->advance();
                $variables[] = $variable->lexeme;
                $variableSpans[] = $variable->span;
            }
            if ($variables === []) {
                $this->fail($this->current(), 'VALUE LABELS requires at least one variable before its value-label pairs.');
            }

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
        );
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
