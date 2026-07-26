<?php

declare(strict_types=1);

namespace OpenStatSpec\Core;

enum FidelitySeverity: string
{
    case Warning = 'warning';
    case Error = 'error';
}
