<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;

abstract class AbstractPdoSqlProfile implements PdoSqlProfile
{
    public function assertCanRepresent(int $sourceVariableCount): void
    {
        if ($sourceVariableCount < 1 || $sourceVariableCount > $this->maximumSourceVariables()) {
            throw new UnsupportedOperation(
                DiagnosticCode::TargetCapabilityExceeded,
                sprintf(
                    '%s supports at most %d source variables in one OpenStatSpec wide table.',
                    $this->driverName(),
                    $this->maximumSourceVariables(),
                ),
            );
        }
    }

    protected function quoteWith(string $identifier, string $delimiter): string
    {
        return $delimiter . str_replace($delimiter, $delimiter . $delimiter, $identifier) . $delimiter;
    }
}
