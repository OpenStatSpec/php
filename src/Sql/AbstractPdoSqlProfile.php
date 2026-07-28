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

    /**
     * @param list<array<string, mixed>>          $variables
     * @param list<list<int|float|string|null>> $rows
     */
    public function assertDataset(array $variables, array $rows): void
    {
        $this->assertCanRepresent(count($variables));
        $declaredRowBytes = 8;
        foreach ($variables as $variable) {
            $kind = is_string($variable['type'] ?? null) && str_contains(strtolower($variable['type']), 'string') ? 'string' : 'numeric';
            $width = $kind === 'string' && is_int($variable['width'] ?? null) ? $variable['width'] : 0;
            if ($width < 0 || $width > $this->maximumValueBytes()) {
                $this->capabilityExceeded('declared string width', $width, $this->maximumValueBytes());
            }
            $declaredRowBytes += $this->rowStorageBytes($kind, $width);
        }
        if ($declaredRowBytes > $this->maximumRowBytes()) {
            $this->capabilityExceeded('declared row size', $declaredRowBytes, $this->maximumRowBytes());
        }
        foreach ($rows as $row) {
            if (count($row) !== count($variables)) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Every source case must contain exactly one value per source variable.');
            }
            foreach ($row as $value) {
                if (is_string($value) && strlen($value) > $this->maximumValueBytes()) {
                    $this->capabilityExceeded('encoded string value', strlen($value), $this->maximumValueBytes());
                }
            }
        }
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
