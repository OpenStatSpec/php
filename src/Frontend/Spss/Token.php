<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;

final readonly class Token
{
    public SourceSpan $span;

    public function __construct(
        public TokenType $type,
        public string $lexeme,
        public int $line,
        public int $column,
        int $startOffset,
        int $endOffset,
        int $endLine,
        int $endColumn,
    ) {
        $this->span = new SourceSpan(
            $startOffset,
            $endOffset,
            $line,
            $column,
            $endLine,
            $endColumn,
        );
    }

    public function isKeyword(string $keyword): bool
    {
        return $this->isWord() && strcasecmp($this->lexeme, $keyword) === 0;
    }

    public function isWord(): bool
    {
        return in_array($this->type, [
            TokenType::Identifier,
            TokenType::Compute,
            TokenType::If,
            TokenType::And,
            TokenType::Or,
            TokenType::Formats,
            TokenType::Variable,
            TokenType::Level,
            TokenType::Nominal,
            TokenType::Ordinal,
            TokenType::Scale,
        ], true);
    }
}
