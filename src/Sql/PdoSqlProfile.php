<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use PDO;

interface PdoSqlProfile
{
    public function driverName(): string;

    public function maximumSourceVariables(): int;

    public function identifierLimit(): int;

    public function identifierLimitUnit(): string;

    public function identifierLimitSource(): string;

    public function generatedIdentifierRepertoire(): string;

    public function maximumValueBytes(): int;

    public function maximumRowBytes(): int;

    public function effectiveMaximumSourceVariables(PDO $pdo): int;

    public function effectiveMaximumValueBytes(PDO $pdo): int;

    public function effectiveMaximumRowBytes(PDO $pdo): int;

    public function effectiveMaximumStatementBytes(PDO $pdo): int;

    /** @return array<string, string> */
    public function effectiveLimitSources(PDO $pdo): array;

    public function serverVersionRange(): string;

    public function ddlAtomic(): bool;

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

    /**
     * @param list<array<string, mixed>>          $variables
     * @param list<list<int|float|string|null>> $rows
     */
    public function assertDataset(array $variables, array $rows, ?PDO $pdo = null): void;
}
