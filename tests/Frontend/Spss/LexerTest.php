<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Frontend\Spss;

use OpenStatSpec\Frontend\Spss\Lexer;
use OpenStatSpec\Frontend\Spss\SpssSyntaxException;
use OpenStatSpec\Frontend\Spss\TokenType;
use PHPUnit\Framework\TestCase;

final class LexerTest extends TestCase
{
    public function testTokenizesMultilineSyntaxAndPreservesQuotedWhitespaceAndEscapes(): void
    {
        $tokens = (new Lexer())->tokenize("* generated comment.\nVARIABLE LABELS score 'A  score''s label'.\n");

        self::assertSame(
            [TokenType::Identifier, TokenType::Identifier, TokenType::Identifier, TokenType::String, TokenType::Terminator, TokenType::EndOfFile],
            array_column($tokens, 'type'),
        );
        self::assertSame("A  score's label", $tokens[3]->lexeme);
        self::assertSame(2, $tokens[0]->line);
    }

    public function testDecimalPointIsNotMistakenForAStatementTerminator(): void
    {
        $tokens = (new Lexer())->tokenize('RECODE score (.5=2).');

        self::assertSame('.5', $tokens[3]->lexeme);
        self::assertSame(TokenType::Number, $tokens[3]->type);
        self::assertSame(1, count(array_filter($tokens, static fn($token): bool => $token->type === TokenType::Terminator)));
    }

    public function testRejectsUnknownCharactersWithPositionedDiagnostic(): void
    {
        try {
            (new Lexer())->tokenize('RECODE score (`=1).');
            self::fail('Unknown syntax unexpectedly tokenized.');
        } catch (SpssSyntaxException $exception) {
            self::assertSame(1, $exception->diagnostics[0]->line);
            self::assertSame(15, $exception->diagnostics[0]->column);
            self::assertStringContainsString('Unexpected character', $exception->diagnostics[0]->message);
        }
    }
}
