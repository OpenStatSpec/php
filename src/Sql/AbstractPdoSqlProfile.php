<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;

abstract class AbstractPdoSqlProfile implements PdoSqlProfile
{
    public function exactValueCondition(string $expression, bool $stringValue): string
    {
        return $expression . ' = ?';
    }

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

    public function identifierLimitUnit(): string
    {
        return 'bytes';
    }

    public function identifierLimitSource(): string
    {
        return 'OpenStatSpec generated-identifier profile boundary';
    }

    public function generatedIdentifierRepertoire(): string
    {
        return 'ASCII lowercase letters, digits, and underscore';
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

    /**
     * @param list<array<string, mixed>>          $variables
     * @param list<list<int|float|string|null>> $rows
     */
    public function assertDataset(array $variables, array $rows, ?PDO $pdo = null): void
    {
        $maximumVariables = $pdo === null ? $this->maximumSourceVariables() : $this->effectiveMaximumSourceVariables($pdo);
        if (count($variables) < 1 || count($variables) > $maximumVariables) {
            $this->capabilityExceeded('source variable count', count($variables), $maximumVariables);
        }
        $maximumValueBytes = $pdo === null ? $this->maximumValueBytes() : $this->effectiveMaximumValueBytes($pdo);
        $maximumRowBytes = $pdo === null ? $this->maximumRowBytes() : $this->effectiveMaximumRowBytes($pdo);
        $declaredRowBytes = 8;
        $storageKinds = [];
        foreach ($variables as $variable) {
            $kind = is_string($variable['type'] ?? null) && str_contains(strtolower($variable['type']), 'string') ? 'string' : 'numeric';
            $storageKinds[] = $kind;
            $width = $kind === 'string' && is_int($variable['width'] ?? null) ? $variable['width'] : 0;
            if ($width < 0 || $width > $maximumValueBytes) {
                $this->capabilityExceeded('declared string width', $width, $maximumValueBytes);
            }
            $declaredRowBytes += $this->rowStorageBytes($kind, $width);
        }
        if ($declaredRowBytes > $maximumRowBytes) {
            $this->capabilityExceeded('declared row size', $declaredRowBytes, $maximumRowBytes);
        }
        $maximumStatementBytes = $pdo === null ? $this->maximumRowBytes() : $this->effectiveMaximumStatementBytes($pdo);
        $maximumEncodedCaseBytes = min($maximumRowBytes, $maximumStatementBytes);
        foreach ($rows as $row) {
            if (count($row) !== count($variables)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every source case must contain exactly one value per source variable.');
            }
            $encodedRowBytes = 0;
            foreach ($row as $index => $value) {
                $kind = $storageKinds[$index];
                if ($kind === 'string' && !is_string($value)) {
                    throw new UnsupportedOperation(
                        DiagnosticCode::InvalidSourceDataset,
                        'SPSS string values must be non-null strings.',
                    );
                }
                if ($kind === 'numeric'
                    && $value !== null
                    && !is_int($value)
                    && !is_float($value)
                ) {
                    throw new UnsupportedOperation(
                        DiagnosticCode::InvalidSourceDataset,
                        'SPSS numeric values must be binary64 numbers or system-missing NULL.',
                    );
                }
                if ($kind === 'numeric'
                    && (is_int($value) || is_float($value))
                    && !is_finite((float) $value)
                ) {
                    throw new UnsupportedOperation(
                        DiagnosticCode::TargetCapabilityExceeded,
                        $this->driverName() . ' rejects non-finite SPSS numeric values before mutation.',
                    );
                }
                if (is_string($value) && strlen($value) > $maximumValueBytes) {
                    $this->capabilityExceeded('encoded string value', strlen($value), $maximumValueBytes);
                }
                $encodedRowBytes += is_string($value) ? strlen($value) : 8;
            }
            if ($encodedRowBytes > $maximumEncodedCaseBytes) {
                $this->capabilityExceeded('encoded case payload', $encodedRowBytes, $maximumEncodedCaseBytes);
            }
        }
    }

    public function effectiveMaximumSourceVariables(PDO $pdo): int
    {
        return $this->maximumSourceVariables();
    }

    public function effectiveMaximumValueBytes(PDO $pdo): int
    {
        return $this->maximumValueBytes();
    }

    public function effectiveMaximumRowBytes(PDO $pdo): int
    {
        return $this->maximumRowBytes();
    }

    public function effectiveMaximumStatementBytes(PDO $pdo): int
    {
        return $this->effectiveMaximumRowBytes($pdo);
    }

    public function effectiveLimitSources(PDO $pdo): array
    {
        return [
            'maximum_source_variables' => 'profile_theoretical_limit',
            'maximum_value_bytes' => 'profile_theoretical_limit',
            'maximum_row_bytes' => 'profile_theoretical_limit',
            'maximum_statement_bytes' => 'profile_theoretical_limit',
            'identifier_limit' => 'profile_theoretical_limit',
        ];
    }

    protected function rowStorageBytes(string $kind, int $declaredWidth): int
    {
        return $kind === 'string' ? $declaredWidth : 8;
    }

    private function capabilityExceeded(string $limit, int $actual, int $maximum): never
    {
        throw new UnsupportedOperation(
            DiagnosticCode::TargetCapabilityExceeded,
            sprintf('%s %s is %d bytes; the declared limit is %d bytes.', $this->driverName(), $limit, $actual, $maximum),
        );
    }

    protected function quoteWith(string $identifier, string $delimiter): string
    {
        return $delimiter . str_replace($delimiter, $delimiter . $delimiter, $identifier) . $delimiter;
    }
}
