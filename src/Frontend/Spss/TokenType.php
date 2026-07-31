<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

enum TokenType
{
    case Identifier;
    case Number;
    case String;
    case LeftParenthesis;
    case RightParenthesis;
    case Equals;
    case Comma;
    case Slash;
    case Terminator;
    case EndOfFile;
}
