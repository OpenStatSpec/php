<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

use OpenStatSpec\Frontend\Spss\Ast\DeleteVariablesStatement;
use OpenStatSpec\Frontend\Spss\Ast\ElseInput;
use OpenStatSpec\Frontend\Spss\Ast\ExecuteStatement;
use OpenStatSpec\Frontend\Spss\Ast\MissingInput;
use OpenStatSpec\Frontend\Spss\Ast\Program;
use OpenStatSpec\Frontend\Spss\Ast\RangeInput;
use OpenStatSpec\Frontend\Spss\Ast\RecodeInput;
use OpenStatSpec\Frontend\Spss\Ast\RecodeOutput;
use OpenStatSpec\Frontend\Spss\Ast\RecodeOutputKind;
use OpenStatSpec\Frontend\Spss\Ast\RecodeRule;
use OpenStatSpec\Frontend\Spss\Ast\RecodeStatement;
use OpenStatSpec\Frontend\Spss\Ast\ScalarValue;
use OpenStatSpec\Frontend\Spss\Ast\SystemMissingInput;
use OpenStatSpec\Frontend\Spss\Ast\StringStatement;
use OpenStatSpec\Frontend\Spss\Ast\ValueInput;
use OpenStatSpec\Frontend\Spss\Ast\ValueLabel;
use OpenStatSpec\Frontend\Spss\Ast\ValueLabelGroup;
use OpenStatSpec\Frontend\Spss\Ast\ValueLabelsStatement;
use OpenStatSpec\Frontend\Spss\Ast\VariableLabelsStatement;

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
                $this->consumeKeyword('LABELS', 'Expected LABELS after VARIABLE.');
                $statements[] = $this->variableLabels($command);
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
                $statements[] = new ExecuteStatement($command->line);
            } elseif ($this->matchKeyword('STRING')) {
                $statements[] = $this->string($command);
            } elseif ($this->matchKeyword('DELETE')) {
                $this->consumeKeyword('VARIABLES', 'Expected VARIABLES after DELETE.');
                $statements[] = $this->deleteVariables($command);
            } else {
                $this->fail($command, sprintf('Unsupported SPSS command %s.', $command->lexeme === '' ? '<end of input>' : $command->lexeme));
            }

            $this->consume(TokenType::Terminator, 'Expected a period after the SPSS command.');
        }

        return new Program($statements);
    }

    private function recode(Token $command): RecodeStatement
    {
        $sources = [];
        do {
            $sources[] = $this->consumeIdentifier('Expected a source variable after RECODE.')->lexeme;
        } while ($this->check(TokenType::Identifier) && !$this->current()->isKeyword('INTO'));

        $rules = [];
        while ($this->match(TokenType::LeftParenthesis)) {
            $input = $this->recodeInput();
            $this->consume(TokenType::Equals, 'Expected = in a RECODE rule.');
            $output = $this->recodeOutput();
            $this->consume(TokenType::RightParenthesis, 'Expected ) after a RECODE rule.');
            $rules[] = new RecodeRule($input, $output);
        }
        if ($rules === []) {
            $this->fail($this->current(), 'RECODE requires at least one parenthesized rule.');
        }

        $targets = [];
        if ($this->matchKeyword('INTO')) {
            do {
                $targets[] = $this->consumeIdentifier('Expected a target variable after INTO.')->lexeme;
            } while ($this->check(TokenType::Identifier));
        }

        return new RecodeStatement($command->line, $sources, $rules, $targets);
    }

    private function recodeInput(): RecodeInput
    {
        if ($this->matchKeyword('SYSMIS')) {
            return new SystemMissingInput();
        }
        if ($this->matchKeyword('MISSING')) {
            return new MissingInput();
        }
        if ($this->matchKeyword('ELSE')) {
            return new ElseInput();
        }
        if ($this->matchKeyword('LOWEST')) {
            $this->consumeKeyword('THRU', 'LOWEST must be followed by THRU.');
            $upper = $this->matchKeyword('HIGHEST') ? null : $this->scalar('Expected an upper bound after LOWEST THRU.');

            return new RangeInput(null, $upper);
        }

        $lower = $this->scalar('Expected a value or selector in a RECODE rule.');
        if (!$this->matchKeyword('THRU')) {
            return new ValueInput($lower);
        }
        $upper = $this->matchKeyword('HIGHEST') ? null : $this->scalar('Expected an upper bound after THRU.');

        return new RangeInput($lower, $upper);
    }

    private function recodeOutput(): RecodeOutput
    {
        if ($this->matchKeyword('COPY')) {
            return new RecodeOutput(RecodeOutputKind::Copy);
        }
        if ($this->matchKeyword('SYSMIS')) {
            return new RecodeOutput(RecodeOutputKind::SystemMissing);
        }

        return new RecodeOutput(RecodeOutputKind::Value, $this->scalar('Expected a value, COPY, or SYSMIS after =.'));
    }

    private function variableLabels(Token $command): VariableLabelsStatement
    {
        $labels = [];
        while (!$this->check(TokenType::Terminator) && !$this->check(TokenType::EndOfFile)) {
            $variable = $this->consumeIdentifier('Expected a variable name in VARIABLE LABELS.')->lexeme;
            $label = $this->consume(TokenType::String, 'Expected a quoted variable label.')->lexeme;
            $labels[$variable] = $label;
        }
        if ($labels === []) {
            $this->fail($this->current(), 'VARIABLE LABELS requires at least one variable and label.');
        }

        return new VariableLabelsStatement($command->line, $labels);
    }

    private function valueLabels(Token $command): ValueLabelsStatement
    {
        $groups = [];
        do {
            $variables = [];
            while ($this->check(TokenType::Identifier)) {
                $variables[] = $this->advance()->lexeme;
            }
            if ($variables === []) {
                $this->fail($this->current(), 'VALUE LABELS requires at least one variable before its value-label pairs.');
            }

            $labels = [];
            while (!$this->check(TokenType::Slash) && !$this->check(TokenType::Terminator) && !$this->check(TokenType::EndOfFile)) {
                $value = $this->scalar('Expected a value in VALUE LABELS.');
                $label = $this->consume(TokenType::String, 'Expected a quoted label after the value.')->lexeme;
                $labels[] = new ValueLabel($value, $label);
            }
            if ($labels === []) {
                $this->fail($this->current(), 'VALUE LABELS requires at least one value-label pair.');
            }
            $groups[] = new ValueLabelGroup($variables, $labels);
        } while ($this->match(TokenType::Slash));

        return new ValueLabelsStatement($command->line, $groups);
    }

    private function string(Token $command): StringStatement
    {
        $variables = [];
        do {
            $variable = $this->consumeIdentifier('Expected a variable name in STRING.')->lexeme;
            if (strcasecmp($variable, 'TO') === 0) {
                $this->fail($this->previous(), 'STRING variable ranges using TO are not supported.');
            }
            $variables[] = $variable;
        } while ($this->check(TokenType::Identifier));
        $this->consume(TokenType::LeftParenthesis, 'Expected a width declaration in STRING.');
        $width = $this->consume(TokenType::Identifier, 'Expected a string width such as A20.')->lexeme;
        if (preg_match('/\AA([1-9][0-9]*)\z/i', $width, $matches) !== 1) {
            $this->fail($this->previous(), 'STRING width must use the SPSS A<n> form.');
        }
        $widthValue = (int) $matches[1];
        if ($widthValue > 32767) {
            $this->fail($this->previous(), 'STRING width must be at most 32767.');
        }
        $this->consume(TokenType::RightParenthesis, 'Expected ) after STRING width.');

        return new StringStatement($command->line, $variables, $widthValue);
    }

    private function deleteVariables(Token $command): DeleteVariablesStatement
    {
        $variables = [];
        do {
            $variables[] = $this->consumeIdentifier('Expected a variable name in DELETE VARIABLES.')->lexeme;
        } while ($this->check(TokenType::Identifier));

        return new DeleteVariablesStatement($command->line, $variables);
    }
    private function scalar(string $message): ScalarValue
    {
        if ($this->match(TokenType::String)) {
            return new ScalarValue($this->previous()->lexeme);
        }
        if (!$this->match(TokenType::Number)) {
            $this->fail($this->current(), $message);
        }
        $lexeme = $this->previous()->lexeme;
        $value = (float) $lexeme;
        if (!is_finite($value)) {
            $this->fail($this->previous(), 'Numeric literals must be finite IEEE-754 binary64 values.');
        }

        return new ScalarValue($value);
    }

    private function consumeIdentifier(string $message): Token
    {
        return $this->consume(TokenType::Identifier, $message);
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

    private function fail(Token $token, string $message): never
    {
        throw new SpssSyntaxException([new Diagnostic($token->line, $token->column, $message)]);
    }
}
