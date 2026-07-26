<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;

abstract class AbstractPdoSqlProfile implements PdoSqlProfile
{
    public function physicalIdentifier(string $source, array $used = []): string
    {
        $base = trim(strtolower((string) preg_replace('/[^a-zA-Z0-9_]+/', '_', $source)), '_');
        $base = $base === '' ? 'data' : $base;
        $limit = $this->identifierLimit();
        $base = substr($base, 0, $limit);

        $candidate = $base;
        for ($suffix = 2; isset($used[$candidate]); ++$suffix) {
            $tail = '_' . $suffix;
            $candidate = substr($base, 0, $limit - strlen($tail)) . $tail;
        }

        return $candidate;
    }

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
