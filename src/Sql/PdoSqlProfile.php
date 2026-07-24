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

    public function assertCanRepresent(int $sourceVariableCount): void;
}
