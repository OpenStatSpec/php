<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;
use OpenStatSpec\Transformation\Diagnostic\TransformationDiagnostic;

final class Lexer
{
    /** @return list<Token> */
    public function tokenize(string $source, bool $officialV03 = false): array
    {
        $tokens = [];
        $offset = str_starts_with($source, "\xEF\xBB\xBF") ? 3 : 0;
        $line = 1;
        $column = 1;
        $atStatementStart = true;
        $length = strlen($source);

        while ($offset < $length) {
            $character = $this->characterAt($source, $offset, $line, $column);
            if (ctype_space($character)) {
                if ($character === "\r") {
                    $this->advanceCarriageReturn($source, $offset, $line, $column);
                    continue;
                }
                $this->advance($character, $offset, $line, $column);
                continue;
            }

            if ($officialV03 && substr($source, $offset, 2) === '/*') {
                $this->comment($source, $offset, $line, $column, true);
                continue;
            }
            if ($atStatementStart && $character === '*') {
                if ($officialV03) {
                    $this->comment($source, $offset, $line, $column, false);
                    continue;
                }
                $this->fail(
                    new SourceSpan($offset, $offset + 1, $line, $column, $line, $column + 1),
                    'Leading-star comments are not supported.',
                    'unsupported_spss_command',
                );
            }

            $tokenOffset = $offset;
            $tokenLine = $line;
            $tokenColumn = $column;
            $wasStatementStart = $atStatementStart;
            $atStatementStart = false;

            if ($this->startsNumber($source, $offset)) {
                $tokens[] = $this->number($source, $offset, $line, $column);
                continue;
            }

            $next = $source[$offset + 1] ?? '';
            if ($character === '/' && $next === '*') {
                $this->fail(
                    new SourceSpan($offset, $offset + 2, $line, $column, $line, $column + 2),
                    'Inline block comments are not supported.',
                );
            }
            $punctuation = match (true) {
                $officialV03 && (($character === '<' && $next === '>') || ($character === '~' && $next === '=')) => TokenType::NotEqual,
                $character === '<' && $next === '=' => TokenType::LessThanOrEqual,
                $character === '>' && $next === '=' => TokenType::GreaterThanOrEqual,
                $character === '<' => TokenType::LessThan,
                $character === '>' => TokenType::GreaterThan,
                $character === '(' => TokenType::LeftParenthesis,
                $character === ')' => TokenType::RightParenthesis,
                $character === '=' => TokenType::Equals,
                $character === ',' => TokenType::Comma,
                $character === '/' => TokenType::Slash,
                $character === '.' => TokenType::Terminator,
                $character === '+' || $character === '-' || $character === '*' => TokenType::ArithmeticOperator,
                default => null,
            };
            if ($punctuation !== null) {
                $lexeme = in_array($punctuation, [TokenType::LessThanOrEqual, TokenType::GreaterThanOrEqual, TokenType::NotEqual], true)
                    ? $character . $next
                    : $character;
                $this->advance($character, $offset, $line, $column);
                if (strlen($lexeme) === 2) {
                    $this->advance($next, $offset, $line, $column);
                }
                $tokens[] = new Token(
                    $punctuation,
                    $lexeme,
                    $tokenLine,
                    $tokenColumn,
                    $tokenOffset,
                    $offset,
                    $line,
                    $column,
                );
                if ($punctuation === TokenType::Terminator) {
                    $atStatementStart = true;
                }
                continue;
            }

            if ($character === '\'' || $character === '"') {
                $tokens[] = $this->string($source, $offset, $line, $column);
                continue;
            }

            if ($this->isIdentifierStart($character)) {
                $start = $offset;
                while ($offset < $length) {
                    $identifierCharacter = $this->characterAt($source, $offset, $line, $column);
                    if (!$this->isIdentifierPart($identifierCharacter)) {
                        break;
                    }
                    $this->advance($identifierCharacter, $offset, $line, $column);
                }
                $lexeme = substr($source, $start, $offset - $start);
                if ($officialV03 && $wasStatementStart && strtoupper($lexeme) === 'COMMENT') {
                    $this->comment($source, $offset, $line, $column, false);
                    $atStatementStart = true;
                    continue;
                }
                $type = match (strtoupper($lexeme)) {
                    'COMPUTE' => TokenType::Compute,
                    'IF' => TokenType::If,
                    'AND' => TokenType::And,
                    'OR' => TokenType::Or,
                    'FORMATS' => TokenType::Formats,
                    'VARIABLE' => TokenType::Variable,
                    'LEVEL' => TokenType::Level,
                    'NOMINAL' => TokenType::Nominal,
                    'ORDINAL' => TokenType::Ordinal,
                    'SCALE' => TokenType::Scale,
                    default => TokenType::Identifier,
                };
                $tokens[] = new Token(
                    $type,
                    $lexeme,
                    $tokenLine,
                    $tokenColumn,
                    $tokenOffset,
                    $offset,
                    $line,
                    $column,
                );
                continue;
            }

            $encoded = json_encode($character, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $this->fail(
                new SourceSpan($offset, $offset + strlen($character), $line, $column, $line, $column + 1),
                sprintf('Unexpected character %s.', $encoded === false ? '?' : $encoded),
            );
        }

        $tokens[] = new Token(TokenType::EndOfFile, '', $line, $column, $offset, $offset, $line, $column);

        return $tokens;
    }

    private function comment(string $source, int &$offset, int &$line, int &$column, bool $block): void
    {
        $start = new SourceSpan($offset, $offset, $line, $column, $line, $column);
        if ($block) {
            $this->advance('/', $offset, $line, $column);
            $this->advance('*', $offset, $line, $column);
        }
        while ($offset < strlen($source)) {
            $pair = substr($source, $offset, 2);
            if ($block && $pair === '/*') {
                $this->fail($start, 'Nested block comments are not supported.');
            }
            if ($block && $pair === '*/') {
                $this->advance('*', $offset, $line, $column);
                $this->advance('/', $offset, $line, $column);
                return;
            }
            $character = $this->characterAt($source, $offset, $line, $column);
            if ($character === "\r") {
                $this->advanceCarriageReturn($source, $offset, $line, $column);
            } else {
                $this->advance($character, $offset, $line, $column);
            }
            if (!$block && $character === '.') {
                return;
            }
        }
        $this->fail($start, 'Unterminated comment.');
    }

    private function string(string $source, int &$offset, int &$line, int &$column): Token
    {
        $quote = $this->characterAt($source, $offset, $line, $column);
        $tokenLine = $line;
        $tokenColumn = $column;
        $tokenOffset = $offset;
        $this->advance($quote, $offset, $line, $column);
        $value = '';
        $length = strlen($source);

        while ($offset < $length) {
            $character = $this->characterAt($source, $offset, $line, $column);
            if ($character !== $quote) {
                if ($character === "\r") {
                    $value .= "\n";
                    $this->advanceCarriageReturn($source, $offset, $line, $column);
                } else {
                    $value .= $character;
                    $this->advance($character, $offset, $line, $column);
                }
                continue;
            }
            $this->advance($character, $offset, $line, $column);
            if ($offset < $length && $this->characterAt($source, $offset, $line, $column) === $quote) {
                $value .= $quote;
                $this->advance($quote, $offset, $line, $column);
                continue;
            }

            return new Token(
                TokenType::String,
                $value,
                $tokenLine,
                $tokenColumn,
                $tokenOffset,
                $offset,
                $line,
                $column,
            );
        }

        $this->fail(
            new SourceSpan($tokenOffset, $offset, $tokenLine, $tokenColumn, $line, $column),
            'String literal is not closed.',
        );
    }

    private function number(string $source, int &$offset, int &$line, int &$column): Token
    {
        $tokenLine = $line;
        $tokenColumn = $column;
        $tokenOffset = $offset;
        $remaining = substr($source, $offset);
        if (preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:[Ee][+-]?\d+)?/', $remaining, $matches) !== 1) {
            $this->fail(
                new SourceSpan($offset, $offset + 1, $line, $column, $line, $column + 1),
                'Invalid numeric literal.',
            );
        }
        $lexeme = $matches[0];
        foreach (str_split($lexeme) as $character) {
            $this->advance($character, $offset, $line, $column);
        }

        return new Token(
            TokenType::Number,
            $lexeme,
            $tokenLine,
            $tokenColumn,
            $tokenOffset,
            $offset,
            $line,
            $column,
        );
    }

    private function startsNumber(string $source, int $offset): bool
    {
        $character = $source[$offset];
        if (ctype_digit($character)) {
            return true;
        }
        $next = $source[$offset + 1] ?? '';
        if ($character === '.' && ctype_digit($next)) {
            return true;
        }

        return ($character === '+' || $character === '-') && (ctype_digit($next) || $next === '.');
    }

    private function isIdentifierStart(string $character): bool
    {
        return preg_match('/\A\p{L}\z/uD', $character) === 1 || str_contains('_@#$', $character);
    }

    private function isIdentifierPart(string $character): bool
    {
        return preg_match('/\A[\p{L}\p{M}\p{N}]\z/uD', $character) === 1 || str_contains('_@#$', $character);
    }

    private function characterAt(string $source, int $offset, int $line, int $column): string
    {
        $firstByte = ord($source[$offset]);
        $byteLength = match (true) {
            $firstByte <= 0x7F => 1,
            $firstByte >= 0xC2 && $firstByte <= 0xDF => 2,
            $firstByte >= 0xE0 && $firstByte <= 0xEF => 3,
            $firstByte >= 0xF0 && $firstByte <= 0xF4 => 4,
            default => null,
        };
        $character = $byteLength === null ? '' : substr($source, $offset, $byteLength);
        if (
            $byteLength === null
            || strlen($character) !== $byteLength
            || preg_match('/\A.\z/usD', $character) !== 1
        ) {
            $this->fail(
                new SourceSpan($offset, $offset + 1, $line, $column, $line, $column + 1),
                sprintf('Invalid UTF-8 sequence beginning with byte 0x%02X.', $firstByte),
            );
        }

        return $character;
    }

    private function advance(string $character, int &$offset, int &$line, int &$column): void
    {
        $offset += strlen($character);
        if ($character === "\n") {
            ++$line;
            $column = 1;
        } else {
            ++$column;
        }
    }

    private function advanceCarriageReturn(string $source, int &$offset, int &$line, int &$column): void
    {
        ++$offset;
        if (($source[$offset] ?? '') === "\n") {
            ++$offset;
        }
        ++$line;
        $column = 1;
    }

    private function fail(SourceSpan $span, string $message, string $code = 'spss_syntax_error'): never
    {
        throw new SpssSyntaxException([
            new TransformationDiagnostic($code, '$.source_text', $message, $span),
        ]);
    }
}
