<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Plan;

enum PlanContract: string
{
    case V01 = 'openstatspec-transformation-plan-v0.1';
    case V02 = 'openstatspec-transformation-plan-v0.2';
}
