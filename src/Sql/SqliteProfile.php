<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use PDO;

final class SqliteProfile extends AbstractPdoSqlProfile
{
    public function driverName(): string
    {
        return 'sqlite';
    }
    public function maximumSourceVariables(): int
    {
        return 1999;
    }
    public function maximumValueBytes(): int
    {
        return 1_000_000_000;
    }
    public function maximumRowBytes(): int
    {
        return 1_000_000_000;
    }
    public function serverVersionRange(): string
    {
        return 'SQLite >=3.24.0 <4.0.0; active version reported at runtime';
    }
    public function ddlAtomic(): bool
    {
        return true;
    }
    public function identifierLimit(): int
    {
        return 255;
    }
    public function identifierLimitSource(): string
    {
        return 'OpenStatSpec profile boundary; SQLite has no comparable fixed identifier limit';
    }
    public function quoteIdentifier(string $identifier): string
    {
        return $this->quoteWith($identifier, '"');
    }
    public function numericType(): string
    {
        return 'REAL';
    }
    public function textType(): string
    {
        return 'TEXT';
    }
    public function effectiveMaximumSourceVariables(PDO $pdo): int
    {
        $maximum = $this->compileOption($pdo, 'MAX_COLUMN');
        return $maximum === null
            ? $this->maximumSourceVariables()
            : min($this->maximumSourceVariables(), max(0, $maximum - 1));
    }
    public function effectiveMaximumValueBytes(PDO $pdo): int
    {
        $maximum = $this->compileOption($pdo, 'MAX_LENGTH');
        return $maximum === null ? $this->maximumValueBytes() : min($this->maximumValueBytes(), $maximum);
    }
    public function effectiveMaximumRowBytes(PDO $pdo): int
    {
        return $this->effectiveMaximumValueBytes($pdo);
    }
    public function effectiveLimitSources(PDO $pdo): array
    {
        return [
            'maximum_source_variables' => $this->compileOption($pdo, 'MAX_COLUMN') === null
                ? 'profile_theoretical_limit; PRAGMA MAX_COLUMN unavailable'
                : 'compile-time PRAGMA compile_options MAX_COLUMN minus technical ordinal, capped by profile',
            'maximum_value_bytes' => $this->compileOption($pdo, 'MAX_LENGTH') === null
                ? 'profile_theoretical_limit; PRAGMA MAX_LENGTH unavailable'
                : 'compile-time PRAGMA compile_options MAX_LENGTH, capped by profile',
            'maximum_row_bytes' => $this->compileOption($pdo, 'MAX_LENGTH') === null
                ? 'profile_theoretical_limit; PRAGMA MAX_LENGTH unavailable'
                : 'compile-time PRAGMA compile_options MAX_LENGTH, capped by profile',
            'maximum_statement_bytes' => $this->compileOption($pdo, 'MAX_LENGTH') === null
                ? 'profile_theoretical_limit; PRAGMA MAX_LENGTH unavailable'
                : 'compile-time PRAGMA compile_options MAX_LENGTH, capped by profile',
            'identifier_limit' => 'OpenStatSpec deterministic profile boundary',
        ];
    }
    private function compileOption(PDO $pdo, string $name): ?int
    {
        $statement = $pdo->query('PRAGMA compile_options');
        if ($statement === false) {
            return null;
        }
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $option) {
            if (is_string($option) && preg_match('/^' . preg_quote($name, '/') . '=(\\d+)$/', $option, $matches) === 1) {
                return (int) $matches[1];
            }
        }
        return null;
    }
}
