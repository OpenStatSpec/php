<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

use InvalidArgumentException;

final class SpssSyntaxException extends InvalidArgumentException
{
    /**
     * @param non-empty-list<Diagnostic> $diagnostics
     */
    public function __construct(public readonly array $diagnostics)
    {
        $first = $diagnostics[0];
        parent::__construct(sprintf('SPSS syntax error at %d:%d: %s', $first->line, $first->column, $first->message));
    }
}
