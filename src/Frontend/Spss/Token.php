<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $lexeme,
        public int $line,
        public int $column,
    ) {}

    public function isKeyword(string $keyword): bool
    {
        return $this->type === TokenType::Identifier && strcasecmp($this->lexeme, $keyword) === 0;
    }
}
