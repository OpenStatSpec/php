<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Frontend\Spss;

use OpenStatSpec\Frontend\Spss\Ast\ElseInput;
use OpenStatSpec\Frontend\Spss\Ast\MissingInput;
use OpenStatSpec\Frontend\Spss\Ast\RangeInput;
use OpenStatSpec\Frontend\Spss\Ast\RecodeOutputKind;
use OpenStatSpec\Frontend\Spss\Ast\RecodeStatement;
use OpenStatSpec\Frontend\Spss\Ast\SystemMissingInput;
use OpenStatSpec\Frontend\Spss\Ast\ValueInput;
use OpenStatSpec\Frontend\Spss\Ast\ValueLabelsStatement;
use OpenStatSpec\Frontend\Spss\Ast\VariableLabelsStatement;
use OpenStatSpec\Frontend\Spss\Parser;
use OpenStatSpec\Frontend\Spss\SpssSyntaxException;
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

    public function testRejectsStringVariableRangesBeforeCreation(): void
    {
        try {
            (new Parser())->parse('STRING c1 TO c4 (A7).');
            self::fail('STRING variable ranges unexpectedly parsed.');
        } catch (SpssSyntaxException $exception) {
            self::assertCount(1, $exception->diagnostics);
            self::assertSame(
                'STRING variable ranges using TO are not supported.',
                $exception->diagnostics[0]->message,
            );
        }
    }

    public function testRejectsDeleteVariableRangesBeforeExecution(): void
    {
        try {
            (new Parser())->parse('DELETE VARIABLES c1 TO c4.');
            self::fail('DELETE VARIABLES ranges unexpectedly parsed.');
        } catch (SpssSyntaxException $exception) {
            self::assertCount(1, $exception->diagnostics);
            self::assertSame(
                'DELETE VARIABLES ranges using TO are not supported.',
                $exception->diagnostics[0]->message,
            );
        }
    }

    public function testFailsClosedForUnknownOrUnterminatedCommands(): void
    {
        foreach (['COMPUTE score=1.', 'RECODE score (1=2)'] as $syntax) {
            try {
                (new Parser())->parse($syntax);
                self::fail('Unsupported or unterminated syntax unexpectedly parsed.');
            } catch (SpssSyntaxException $exception) {
                self::assertNotEmpty($exception->diagnostics);
            }
        }
    }
}
