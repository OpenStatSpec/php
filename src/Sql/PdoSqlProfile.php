<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

interface PdoSqlProfile
{
    public function driverName(): string;

    public function maximumSourceVariables(): int;

    public function identifierLimit(): int;

    public function quoteIdentifier(string $identifier): string;

    public function numericType(): string;

    public function textType(): string;

    /**
     * Creates a deterministic, dialect-safe physical identifier. The source name
     * itself remains authoritative in the variables catalogue.
     *
     * @param array<string, true> $used
     */
    public function physicalIdentifier(string $source, array $used = []): string;

    public function assertCanRepresent(int $sourceVariableCount): void;
}
