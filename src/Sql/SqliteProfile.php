<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

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
        return 'SQLite 3.35+';
    }
    public function ddlAtomic(): bool
    {
        return true;
    }
    public function identifierLimit(): int
    {
        return 255;
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
}
