<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

enum TokenType
{
    case Identifier;
    case Compute;
    case If;
    case And;
    case Or;
    case Formats;
    case Variable;
    case Level;
    case Nominal;
    case Ordinal;
    case Scale;
    case Number;
    case String;
    case LeftParenthesis;
    case RightParenthesis;
    case Equals;
    case NotEqual;
    case LessThan;
    case LessThanOrEqual;
    case GreaterThan;
    case GreaterThanOrEqual;
    case ArithmeticOperator;
    case Comma;
    case Slash;
    case Terminator;
    case EndOfFile;
}
