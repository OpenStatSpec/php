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
    public function maximumValueBytes(): int
    {
        return 4_294_967_295;
    }
    public function maximumRowBytes(): int
    {
        return 65_535;
    }
    public function serverVersionRange(): string
    {
        return 'MySQL 8.0+ or MariaDB 10.6+';
    }
    public function ddlAtomic(): bool
    {
        return false;
    }
    protected function rowStorageBytes(string $kind, int $declaredWidth): int
    {
        return $kind === 'string' ? 20 : 8;
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
