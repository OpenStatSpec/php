<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

final class MySqlProfile extends AbstractPdoSqlProfile
{
    public function driverName(): string
    {
        return 'mysql';
    }
    public function maximumSourceVariables(): int
    {
        return 1016;
    }
    public function identifierLimit(): int
    {
        return 64;
    }
    public function quoteIdentifier(string $identifier): string
    {
        return $this->quoteWith($identifier, "`");
    }
    public function numericType(): string
    {
        return 'DOUBLE';
    }
    public function textType(): string
    {
        return 'LONGTEXT';
    }
}
