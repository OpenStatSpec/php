<?php

declare(strict_types=1);

namespace OpenStatSpec\Core;

use LogicException;

final class UnsupportedOperation extends LogicException
{
    public function __construct(public readonly DiagnosticCode $diagnosticCode, string $message)
    {
        parent::__construct($message);
    }
}
