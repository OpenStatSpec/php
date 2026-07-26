<?php

declare(strict_types=1);

namespace OpenStatSpec\Core;

enum DiagnosticCode: string
{
    case UnsupportedOperation = 'unsupported_operation';
    case ExternalEngineUnavailable = 'external_engine_unavailable';
    case TargetCapabilityExceeded = 'target_capability_exceeded';
    case UnsupportedSourceFormat = 'unsupported_source_format';
    case InvalidSourceDataset = 'invalid_source_dataset';
    case UnsupportedSqlDriver = 'unsupported_sql_driver';
    case SqlProfileOperationUnavailable = 'sql_profile_operation_unavailable';
    case FidelityLossRequiresAcceptance = 'fidelity_loss_requires_acceptance';
}
