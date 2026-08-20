<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Frontend\Spss;

use OpenStatSpec\Frontend\Spss\Ast\ElseInput;
use OpenStatSpec\Frontend\Spss\Ast\BooleanPredicate;
use OpenStatSpec\Frontend\Spss\Ast\Comparison;
use OpenStatSpec\Frontend\Spss\Ast\ComputeStatement;
use OpenStatSpec\Frontend\Spss\Ast\ExecuteStatement;
use OpenStatSpec\Frontend\Spss\Ast\FormatsStatement;
use OpenStatSpec\Frontend\Spss\Ast\IfStatement;
use OpenStatSpec\Frontend\Spss\Ast\LiteralOperand;
use OpenStatSpec\Frontend\Spss\Ast\MissingInput;
use OpenStatSpec\Frontend\Spss\Ast\RangeInput;
use OpenStatSpec\Frontend\Spss\Ast\RecodeOutputKind;
use OpenStatSpec\Frontend\Spss\Ast\RecodeStatement;
use OpenStatSpec\Frontend\Spss\Ast\SystemMissingInput;
use OpenStatSpec\Frontend\Spss\Ast\ValueInput;
use OpenStatSpec\Frontend\Spss\Ast\ValueLabelsStatement;
use OpenStatSpec\Frontend\Spss\Ast\VariableLabelsStatement;
use OpenStatSpec\Frontend\Spss\Ast\VariableLevelStatement;
use OpenStatSpec\Frontend\Spss\Ast\VariableOperand;
use OpenStatSpec\Frontend\Spss\Parser;
use OpenStatSpec\Frontend\Spss\Request\InputSchema;
use OpenStatSpec\Frontend\Spss\Request\InputVariable;
use OpenStatSpec\Frontend\Spss\Request\SpssFrontendRequest;
use OpenStatSpec\Frontend\Spss\SpssSyntaxException;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function testParsesSupportedRecodeSelectorsActionsAndInto(): void
    {
        $program = (new Parser())->parse(<<<'SPSS'
            RECODE score
              (1=10)
              (2 THRU 4=20)
              (LOWEST THRU 0=SYSMIS)
              (5 THRU HIGHEST=COPY)
              (SYSMIS=99)
              (MISSING=98)
              (ELSE=COPY)
              INTO band.
            SPSS);

        self::assertCount(1, $program->statements);
        $statement = $program->statements[0];
        self::assertInstanceOf(RecodeStatement::class, $statement);
        self::assertSame(['score'], $statement->sources);
        self::assertSame(['band'], $statement->targets);
        self::assertInstanceOf(ValueInput::class, $statement->rules[0]->input);
        self::assertInstanceOf(RangeInput::class, $statement->rules[1]->input);
        $lowest = $statement->rules[2]->input;
        self::assertInstanceOf(RangeInput::class, $lowest);
        self::assertNull($lowest->lower);
        $highest = $statement->rules[3]->input;
        self::assertInstanceOf(RangeInput::class, $highest);
        self::assertNull($highest->upper);
        self::assertInstanceOf(SystemMissingInput::class, $statement->rules[4]->input);
        self::assertInstanceOf(MissingInput::class, $statement->rules[5]->input);
        self::assertInstanceOf(ElseInput::class, $statement->rules[6]->input);
        self::assertSame(RecodeOutputKind::SystemMissing, $statement->rules[2]->output->kind);
        self::assertSame(RecodeOutputKind::Copy, $statement->rules[3]->output->kind);
    }

    public function testParsesCommaSeparatedRecodeSelectorsInSourceOrder(): void
    {
        $program = (new Parser())->parse('RECODE q1 (1,2,3 = 0).');

        $statement = $program->statements[0];
        self::assertInstanceOf(RecodeStatement::class, $statement);
        self::assertCount(1, $statement->rules);
        $rule = $statement->rules[0];
        self::assertInstanceOf(ValueInput::class, $rule->input);
        self::assertSame(['1', '2', '3'], array_column($rule->input->values, 'value'));
        self::assertSame(['1', '2', '3'], array_column($rule->input->values, 'numericToken'));
        self::assertNotNull($rule->output->value);
        self::assertSame('0', $rule->output->value->value);
        self::assertSame('0', $rule->output->value->numericToken);
    }

    public function testParsesVariableAndValueLabelGroups(): void
    {
        $program = (new Parser())->parse(<<<'SPSS'
            VARIABLE LABELS score 'Overall score' band 'Score band'.
            VALUE LABELS score 1 'One' 2 'Two' / band 'L' 'Low' 'H' 'High'.
            SPSS);

        self::assertInstanceOf(VariableLabelsStatement::class, $program->statements[0]);
        self::assertSame(['score' => 'Overall score', 'band' => 'Score band'], $program->statements[0]->labels);
        self::assertInstanceOf(ValueLabelsStatement::class, $program->statements[1]);
        self::assertCount(2, $program->statements[1]->groups);
        self::assertSame(['score'], $program->statements[1]->groups[0]->variables);
        self::assertSame(['band'], $program->statements[1]->groups[1]->variables);
    }

    public function testFailsClosedForUnknownOrUnterminatedCommands(): void
    {
        foreach (['SORT score.', 'RECODE score (1=2)'] as $syntax) {
            try {
                (new Parser())->parse($syntax);
                self::fail('Unsupported or unterminated syntax unexpectedly parsed.');
            } catch (SpssSyntaxException $exception) {
                self::assertNotEmpty($exception->diagnostics);
            }
        }
    }

    public function testParsesEveryFrontend02CommandAndPreservesNumericTokenText(): void
    {
        $program = (new Parser())->parse(
            "COMPUTE target = 0.\n"
            . "IF (a = 1 AND b >= 2 OR c < 3) target = a.\n"
            . "FORMATS target (F8.2).\n"
            . "VARIABLE LEVEL target (SCALE).\n"
            . 'EXECUTE.',
        );

        self::assertInstanceOf(ComputeStatement::class, $program->statements[0]);
        self::assertInstanceOf(LiteralOperand::class, $program->statements[0]->expression);
        self::assertSame('0', $program->statements[0]->expression->token);
        self::assertInstanceOf(IfStatement::class, $program->statements[1]);
        $predicate = $program->statements[1]->predicate;
        self::assertInstanceOf(BooleanPredicate::class, $predicate);
        self::assertSame('or', $predicate->operator);
        self::assertInstanceOf(BooleanPredicate::class, $predicate->operands[0]);
        self::assertSame('and', $predicate->operands[0]->operator);
        self::assertInstanceOf(FormatsStatement::class, $program->statements[2]);
        self::assertSame('F', $program->statements[2]->targets[0]->family);
        self::assertSame(8, $program->statements[2]->targets[0]->width);
        self::assertSame(2, $program->statements[2]->targets[0]->decimals);
        self::assertInstanceOf(VariableLevelStatement::class, $program->statements[3]);
        self::assertSame('scale', $program->statements[3]->groups[0]->level);
        self::assertInstanceOf(ExecuteStatement::class, $program->statements[4]);
        self::assertSame(5, $program->statements[4]->span->startLine);
        self::assertSame(0, $program->statements[0]->span->startOffset);
        self::assertSame(18, $program->statements[0]->span->endOffset);
    }

    public function testBooleanPrecedenceParenthesesAndSameOperatorFlatteningPreserveOrder(): void
    {
        $program = (new Parser())->parse(
            'IF ((a = 1 OR b = 2) AND c = 3 AND d = 4) target = source.',
        );

        $statement = $program->statements[0];
        self::assertInstanceOf(IfStatement::class, $statement);
        $predicate = $statement->predicate;
        self::assertInstanceOf(BooleanPredicate::class, $predicate);
        self::assertSame('and', $predicate->operator);
        self::assertCount(3, $predicate->operands);
        $or = $predicate->operands[0];
        self::assertInstanceOf(BooleanPredicate::class, $or);
        self::assertSame('or', $or->operator);
        $a = $or->operands[0];
        $b = $or->operands[1];
        $c = $predicate->operands[1];
        $d = $predicate->operands[2];
        self::assertInstanceOf(Comparison::class, $a);
        self::assertInstanceOf(Comparison::class, $b);
        self::assertInstanceOf(Comparison::class, $c);
        self::assertInstanceOf(Comparison::class, $d);
        self::assertInstanceOf(VariableOperand::class, $a->left);
        self::assertInstanceOf(VariableOperand::class, $b->left);
        self::assertInstanceOf(VariableOperand::class, $c->left);
        self::assertInstanceOf(VariableOperand::class, $d->left);
        self::assertSame(['a', 'b', 'c', 'd'], [$a->left->name, $b->left->name, $c->left->name, $d->left->name]);
    }

    public function testParsesAllComparisonOperandPermutations(): void
    {
        $program = (new Parser())->parse('IF (a = b AND 1 < c AND 2 >= 1 AND c > 3) target = -1.25e+2.');

        $statement = $program->statements[0];
        self::assertInstanceOf(IfStatement::class, $statement);
        $predicate = $statement->predicate;
        self::assertInstanceOf(BooleanPredicate::class, $predicate);
        $first = $predicate->operands[0];
        $second = $predicate->operands[1];
        $third = $predicate->operands[2];
        $fourth = $predicate->operands[3];
        self::assertInstanceOf(Comparison::class, $first);
        self::assertInstanceOf(Comparison::class, $second);
        self::assertInstanceOf(Comparison::class, $third);
        self::assertInstanceOf(Comparison::class, $fourth);
        self::assertInstanceOf(VariableOperand::class, $first->left);
        self::assertInstanceOf(VariableOperand::class, $first->right);
        self::assertInstanceOf(LiteralOperand::class, $second->left);
        self::assertInstanceOf(VariableOperand::class, $second->right);
        self::assertInstanceOf(LiteralOperand::class, $third->left);
        self::assertInstanceOf(LiteralOperand::class, $third->right);
        self::assertInstanceOf(VariableOperand::class, $fourth->left);
        self::assertInstanceOf(LiteralOperand::class, $fourth->right);
        self::assertInstanceOf(LiteralOperand::class, $statement->expression);
        self::assertSame('-1.25e+2', $statement->expression->token);
    }

    public function testParsesStrictGreaterThanComparison(): void
    {
        $program = (new Parser())->parse('IF (a > 1) target = 1.');
        $statement = $program->statements[0];
        self::assertInstanceOf(IfStatement::class, $statement);
        self::assertInstanceOf(Comparison::class, $statement->predicate);
        self::assertSame('>', $statement->predicate->operator);
    }

    public function testKeywordTokensRemainValidContextualVariableNames(): void
    {
        $program = (new Parser())->parse(
            "RECODE scale (1=2).\n"
            . "VARIABLE LABELS compute 'Computed'.\n"
            . "COMPUTE if = scale.\n"
            . "IF (scale = 1) if = compute.\n"
            . "FORMATS scale (F8.2).\n"
            . 'VARIABLE LEVEL scale compute (NOMINAL).',
        );

        self::assertInstanceOf(RecodeStatement::class, $program->statements[0]);
        self::assertSame(['scale'], $program->statements[0]->sources);
        self::assertInstanceOf(ComputeStatement::class, $program->statements[2]);
        self::assertSame('if', $program->statements[2]->target);
        self::assertInstanceOf(IfStatement::class, $program->statements[3]);
        self::assertSame('if', $program->statements[3]->target);
        $levels = $program->statements[5];
        self::assertInstanceOf(VariableLevelStatement::class, $levels);
        self::assertSame(['scale', 'compute'], $levels->groups[0]->variables);
    }

    public function testGroupedMetadataCommandsPreserveGroupAndVariableOrder(): void
    {
        $program = (new Parser())->parse(
            "FORMATS a (F8.2) b (f10.3).\n"
            . 'VARIABLE LEVEL a b (ORDINAL) / c target (NOMINAL).',
        );

        $formats = $program->statements[0];
        $levels = $program->statements[1];
        self::assertInstanceOf(FormatsStatement::class, $formats);
        self::assertInstanceOf(VariableLevelStatement::class, $levels);
        self::assertSame(['a', 'b'], array_column($formats->targets, 'variable'));
        self::assertSame([8, 10], array_column($formats->targets, 'width'));
        self::assertSame([['a', 'b'], ['c', 'target']], array_column($levels->groups, 'variables'));
        self::assertSame(['ordinal', 'nominal'], array_column($levels->groups, 'level'));
    }

    #[DataProvider('rejectedFrontend02SyntaxProvider')]
    public function testRejectedFrontend02SyntaxHasStableCodeAndExactSpan(
        string $source,
        string $code,
        int $startOffset,
        int $endOffset,
    ): void {
        try {
            (new Parser())->parse($source);
            self::fail('Unsupported frontend syntax unexpectedly parsed.');
        } catch (SpssSyntaxException $exception) {
            self::assertInstanceOf(TransformationFailure::class, $exception);
            self::assertSame($code, $exception->diagnosticCode());
            $span = $exception->diagnostics[0]->span;
            self::assertNotNull($span);
            self::assertSame($startOffset, $span->startOffset);
            self::assertSame($endOffset, $span->endOffset);
            self::assertSame(1, $span->startLine);
            self::assertSame($startOffset + 1, $span->startColumn);
            self::assertSame(1, $span->endLine);
            self::assertSame($endOffset + 1, $span->endColumn);
        }
    }

    /** @return iterable<string, array{string, string, int, int}> */
    public static function rejectedFrontend02SyntaxProvider(): iterable
    {
        yield 'IF needs outer parentheses' => ['IF a = 1 target = 1.', 'spss_syntax_error', 3, 4];
        yield 'IF ELSE is not in the grammar' => ['IF (a = 1) target = 1 ELSE target = 0.', 'spss_syntax_error', 22, 26];
        yield 'arithmetic is unsupported' => ['COMPUTE target = a + 1.', 'expression_type_unsupported', 19, 20];
        yield 'adjacent addition is unsupported' => ['COMPUTE target = a+1.', 'expression_type_unsupported', 18, 20];
        yield 'adjacent subtraction is unsupported' => ['COMPUTE target = a-1.', 'expression_type_unsupported', 18, 20];
        yield 'predicate arithmetic is unsupported' => ['IF (a + 1 = 2) target = 1.', 'expression_type_unsupported', 6, 7];
        yield 'NOT is unsupported' => ['IF (NOT a = 1) target = 1.', 'expression_type_unsupported', 4, 7];
        yield 'string assignment is unsupported' => ["COMPUTE target = 'x'.", 'expression_type_unsupported', 17, 20];
        yield 'string predicate is unsupported' => ["IF (color = 'R') target = 1.", 'expression_type_unsupported', 12, 15];
        yield 'non-F format is invalid' => ['FORMATS target (A8).', 'invalid_format', 16, 18];
        yield 'unknown level is invalid syntax' => ['VARIABLE LEVEL target (UNKNOWN).', 'spss_syntax_error', 23, 30];
        yield 'star comment is unsupported command' => ['* comment.', 'unsupported_spss_command', 0, 1];
        yield 'COMMENT is unsupported command' => ['COMMENT text.', 'unsupported_spss_command', 0, 7];
        yield 'unknown command is unsupported' => ['SORT CASES BY a.', 'unsupported_spss_command', 0, 4];
        yield 'keyword word is unsupported at command start' => ['SCALE.', 'unsupported_spss_command', 0, 5];
        yield 'inline block comment is syntax error' => ['COMPUTE target = a/* comment */.', 'spss_syntax_error', 18, 20];
        yield 'leading plus is not numeric grammar' => ['COMPUTE target = +1.', 'spss_syntax_error', 17, 19];
        yield 'leading decimal is not numeric grammar' => ['COMPUTE target = .5.', 'spss_syntax_error', 17, 19];
        yield 'leading zero is not numeric grammar' => ['COMPUTE target = 01.', 'spss_syntax_error', 17, 19];
        yield 'NaN is not numeric grammar' => ['COMPUTE target = NaN.', 'spss_syntax_error', 17, 20];
        yield 'Infinity is not numeric grammar' => ['COMPUTE target = Infinity.', 'spss_syntax_error', 17, 25];
        yield 'trailing decimal point is not numeric grammar' => ['COMPUTE target = 1..', 'spss_syntax_error', 19, 20];
    }

    public function testMultilineDiagnosticHasExactStartAndEndCoordinates(): void
    {
        try {
            (new Parser())->parse("COMPUTE target = 0.\nIF x = 1 target = 1.");
            self::fail('Unparenthesized IF unexpectedly parsed.');
        } catch (SpssSyntaxException $exception) {
            $span = $exception->diagnostics[0]->span;
            self::assertNotNull($span);
            self::assertSame(23, $span->startOffset);
            self::assertSame(24, $span->endOffset);
            self::assertSame(2, $span->startLine);
            self::assertSame(4, $span->startColumn);
            self::assertSame(2, $span->endLine);
            self::assertSame(5, $span->endColumn);
        }
    }

    public function testRequestDtosRetainExactSourceAndOrderedSchema(): void
    {
        $schema = new InputSchema([
            new InputVariable('first', 'numeric'),
            new InputVariable('second', 'string'),
        ]);
        $request = new SpssFrontendRequest(
            'openstatspec-spss-syntax-frontend-v0.2',
            'parent',
            $schema,
            "COMPUTE target = 0.\r\nEXECUTE.",
        );

        self::assertSame(['first', 'second'], array_column($request->inputSchema->variables, 'name'));
        self::assertSame("COMPUTE target = 0.\r\nEXECUTE.", $request->sourceText);
    }
}
