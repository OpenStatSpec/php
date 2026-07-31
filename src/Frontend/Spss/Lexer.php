<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

final class Lexer
{
    /** @return list<Token> */
    public function tokenize(string $source): array
    {
        $source = preg_replace('/^\xEF\xBB\xBF/', '', $source) ?? $source;
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $tokens = [];
        $offset = 0;
        $line = 1;
        $column = 1;
        $atStatementStart = true;
        $length = strlen($source);

        while ($offset < $length) {
            $character = $source[$offset];
            if (ctype_space($character)) {
                $this->advance($character, $offset, $line, $column);
                continue;
            }

            if ($atStatementStart && $character === '*') {
                while ($offset < $length && $source[$offset] !== '.') {
                    $this->advance($source[$offset], $offset, $line, $column);
                }
                if ($offset === $length) {
                    $this->fail($line, $column, 'Comment is missing its period terminator.');
                }
                $this->advance('.', $offset, $line, $column);
                continue;
            }

            $tokenLine = $line;
            $tokenColumn = $column;
            $atStatementStart = false;

            if ($this->startsNumber($source, $offset)) {
                $tokens[] = $this->number($source, $offset, $line, $column);
                continue;
            }

            $punctuation = match ($character) {
                '(' => TokenType::LeftParenthesis,
                ')' => TokenType::RightParenthesis,
                '=' => TokenType::Equals,
                ',' => TokenType::Comma,
                '/' => TokenType::Slash,
                '.' => TokenType::Terminator,
                default => null,
            };
            if ($punctuation !== null) {
                $tokens[] = new Token($punctuation, $character, $tokenLine, $tokenColumn);
                $this->advance($character, $offset, $line, $column);
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
                while ($offset < $length && $this->isIdentifierPart($source[$offset])) {
                    $this->advance($source[$offset], $offset, $line, $column);
                }
                $tokens[] = new Token(TokenType::Identifier, substr($source, $start, $offset - $start), $tokenLine, $tokenColumn);
                continue;
            }

            $this->fail($line, $column, sprintf('Unexpected character %s.', json_encode($character, JSON_THROW_ON_ERROR)));
        }

        $tokens[] = new Token(TokenType::EndOfFile, '', $line, $column);

        return $tokens;
    }

    private function string(string $source, int &$offset, int &$line, int &$column): Token
    {
        $quote = $source[$offset];
        $tokenLine = $line;
        $tokenColumn = $column;
        $this->advance($quote, $offset, $line, $column);
        $value = '';
        $length = strlen($source);

        while ($offset < $length) {
            $character = $source[$offset];
            if ($character !== $quote) {
                $value .= $character;
                $this->advance($character, $offset, $line, $column);
                continue;
            }
            $this->advance($character, $offset, $line, $column);
            if ($offset < $length && $source[$offset] === $quote) {
                $value .= $quote;
                $this->advance($quote, $offset, $line, $column);
                continue;
            }

            return new Token(TokenType::String, $value, $tokenLine, $tokenColumn);
        }

        $this->fail($tokenLine, $tokenColumn, 'String literal is not closed.');
    }

    private function number(string $source, int &$offset, int &$line, int &$column): Token
    {
        $tokenLine = $line;
        $tokenColumn = $column;
        $remaining = substr($source, $offset);
        if (preg_match('/^[+-]?(?:(?:\d+(?:\.\d*)?)|(?:\.\d+))(?:[Ee][+-]?\d+)?/', $remaining, $matches) !== 1) {
            $this->fail($line, $column, 'Invalid numeric literal.');
        }
        $lexeme = $matches[0];
        foreach (str_split($lexeme) as $character) {
            $this->advance($character, $offset, $line, $column);
        }

        return new Token(TokenType::Number, $lexeme, $tokenLine, $tokenColumn);
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
        return ctype_alpha($character) || str_contains('_@#$', $character);
    }

    private function isIdentifierPart(string $character): bool
    {
        return ctype_alnum($character) || str_contains('_@#$', $character);
    }

    private function advance(string $character, int &$offset, int &$line, int &$column): void
    {
        ++$offset;
        if ($character === "\n") {
            ++$line;
            $column = 1;
        } else {
            ++$column;
        }
    }

    private function fail(int $line, int $column, string $message): never
    {
        throw new SpssSyntaxException([new Diagnostic($line, $column, $message)]);
    }
}
