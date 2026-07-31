<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss\Ast;

enum RecodeOutputKind
{
    case Value;
    case Copy;
    case SystemMissing;
}
