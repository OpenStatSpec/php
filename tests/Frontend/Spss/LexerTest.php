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
        $tokens = (new Lexer())->tokenize("\nVARIABLE LABELS score 'A  score''s label'.\n");

        self::assertSame(
            [TokenType::Variable, TokenType::Identifier, TokenType::Identifier, TokenType::String, TokenType::Terminator, TokenType::EndOfFile],
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

    public function testTokenizesPrecomposedAndDecomposedUnicodeIdentifiers(): void
    {
        $precomposed = "\u{00E9}chelle";
        $decomposed = "e\u{0301}chelle";
        $tokens = (new Lexer())->tokenize($precomposed . ' ' . $decomposed . '.');

        self::assertSame(TokenType::Identifier, $tokens[0]->type);
        self::assertSame($precomposed, $tokens[0]->lexeme);
        self::assertSame(TokenType::Identifier, $tokens[1]->type);
        self::assertSame($decomposed, $tokens[1]->lexeme);
    }

    public function testRejectsInvalidTrailingUtf8Byte(): void
    {
        $this->expectException(SpssSyntaxException::class);

        (new Lexer())->tokenize("RECODE score (1=2).\xC3");
    }

    public function testRejectsUnknownCharactersWithPositionedDiagnostic(): void
    {
        try {
            (new Lexer())->tokenize('RECODE score (`=1).');
            self::fail('Unknown syntax unexpectedly tokenized.');
        } catch (SpssSyntaxException $exception) {
            $span = $exception->diagnostics[0]->span;
            self::assertNotNull($span);
            self::assertSame(1, $span->startLine);
            self::assertSame(15, $span->startColumn);
            self::assertStringContainsString('Unexpected character', $exception->diagnostics[0]->message);
        }
    }

    public function testTokenizesFrontend02KeywordsComparisonsAndExactBoundaries(): void
    {
        $tokens = (new Lexer())->tokenize("compute target = 0.\nIF (a >= 1 AND b < 2 OR c <= 3) target = 4.");

        self::assertSame(TokenType::Compute, $tokens[0]->type);
        self::assertSame(TokenType::If, $tokens[5]->type);
        self::assertSame(TokenType::GreaterThanOrEqual, $tokens[8]->type);
        self::assertSame(TokenType::And, $tokens[10]->type);
        self::assertSame(TokenType::LessThan, $tokens[12]->type);
        self::assertSame(TokenType::Or, $tokens[14]->type);
        self::assertSame(TokenType::LessThanOrEqual, $tokens[16]->type);
        self::assertSame(0, $tokens[0]->span->startOffset);
        self::assertSame(7, $tokens[0]->span->endOffset);
        self::assertSame(1, $tokens[0]->span->startLine);
        self::assertSame(1, $tokens[0]->span->startColumn);
        self::assertSame(1, $tokens[0]->span->endLine);
        self::assertSame(8, $tokens[0]->span->endColumn);
        self::assertSame(20, $tokens[5]->span->startOffset);
        self::assertSame(2, $tokens[5]->span->startLine);
        self::assertSame(1, $tokens[5]->span->startColumn);
    }

    public function testOffsetsRemainExactAcrossCrLfLineEndings(): void
    {
        $tokens = (new Lexer())->tokenize("COMPUTE x = 0.\r\nEXECUTE.");

        self::assertSame(16, $tokens[5]->span->startOffset);
        self::assertSame(2, $tokens[5]->span->startLine);
        self::assertSame(1, $tokens[5]->span->startColumn);
    }

    public function testNormalizesBareCrInsideStringsWithoutLosingExactOffsets(): void
    {
        $tokens = (new Lexer())->tokenize("VARIABLE LABELS x 'a\rb'.\rEXECUTE.");

        self::assertSame("a\nb", $tokens[3]->lexeme);
        self::assertSame(1, $tokens[3]->span->startLine);
        self::assertSame(2, $tokens[3]->span->endLine);
        self::assertSame(23, $tokens[3]->span->endOffset);
        self::assertSame(3, $tokens[5]->span->startLine);
        self::assertSame(25, $tokens[5]->span->startOffset);
    }
}
