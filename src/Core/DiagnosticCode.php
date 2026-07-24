<?php

declare(strict_types=1);

namespace OpenStatSpec\Core;

enum DiagnosticCode: string
{
    case UnsupportedOperation = 'unsupported_operation';
    case ExternalEngineUnavailable = 'external_engine_unavailable';
    case TargetCapabilityExceeded = 'target_capability_exceeded';
}
