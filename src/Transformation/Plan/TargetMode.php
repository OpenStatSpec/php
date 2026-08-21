<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan;

enum TargetMode: string
{
    case Create = 'create';
    case Replace = 'replace';
}
