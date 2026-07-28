<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

final class PostgreSqlProfile extends AbstractPdoSqlProfile
{
    public function driverName(): string
    {
        return 'pgsql';
    }
    public function maximumSourceVariables(): int
    {
        return 1599;
    }
    public function maximumValueBytes(): int
    {
        return 1_073_741_823;
    }
    public function maximumRowBytes(): int
    {
        return 1_073_741_823;
    }
    public function serverVersionRange(): string
    {
        return 'PostgreSQL 17.x or 18.x';
    }
    public function ddlAtomic(): bool
    {
        return true;
    }
    public function identifierLimit(): int
    {
        return 63;
    }
    public function identifierLimitSource(): string
    {
        return 'PostgreSQL NAMEDATALEN minus one native byte limit';
    }
    public function quoteIdentifier(string $identifier): string
    {
        return $this->quoteWith($identifier, '"');
    }
    public function numericType(): string
    {
        return 'DOUBLE PRECISION';
    }
    public function textType(): string
    {
        return 'TEXT';
    }
}
