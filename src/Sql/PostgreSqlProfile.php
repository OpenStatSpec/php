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
    public function identifierLimit(): int
    {
        return 63;
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
