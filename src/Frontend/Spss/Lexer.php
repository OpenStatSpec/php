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
            $character = $this->characterAt($source, $offset, $line, $column);
            if (ctype_space($character)) {
                $this->advance($character, $offset, $line, $column);
                continue;
            }

            if ($atStatementStart && $character === '*') {
                while ($offset < $length && $this->characterAt($source, $offset, $line, $column) !== '.') {
                    $this->advance($this->characterAt($source, $offset, $line, $column), $offset, $line, $column);
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
                while ($offset < $length) {
                    $identifierCharacter = $this->characterAt($source, $offset, $line, $column);
                    if (!$this->isIdentifierPart($identifierCharacter)) {
                        break;
                    }
                    $this->advance($identifierCharacter, $offset, $line, $column);
                }
                $tokens[] = new Token(TokenType::Identifier, substr($source, $start, $offset - $start), $tokenLine, $tokenColumn);
                continue;
            }

            $encoded = json_encode($character, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $this->fail($line, $column, sprintf('Unexpected character %s.', $encoded === false ? '?' : $encoded));
        }

        $tokens[] = new Token(TokenType::EndOfFile, '', $line, $column);

        return $tokens;
    }

    private function string(string $source, int &$offset, int &$line, int &$column): Token
    {
        $quote = $this->characterAt($source, $offset, $line, $column);
        $tokenLine = $line;
        $tokenColumn = $column;
        $this->advance($quote, $offset, $line, $column);
        $value = '';
        $length = strlen($source);

        while ($offset < $length) {
            $character = $this->characterAt($source, $offset, $line, $column);
            if ($character !== $quote) {
                $value .= $character;
                $this->advance($character, $offset, $line, $column);
                continue;
            }
            $this->advance($character, $offset, $line, $column);
            if ($offset < $length && $this->characterAt($source, $offset, $line, $column) === $quote) {
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
                $line,
                $column,
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

    private function fail(int $line, int $column, string $message): never
    {
        throw new SpssSyntaxException([new Diagnostic($line, $column, $message)]);
    }
}
