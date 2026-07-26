<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

/** A deterministic PostgreSQL native-table DDL plan. */
final readonly class PostgreSqlWideTableDefinition
{
    /**
     * @param list<array{sourceName: string, columnName: string, storageKind: 'numeric'|'string'}> $columns
     */
    public function __construct(
        public string $tableName,
        public string $createSql,
        public array $columns,
    ) {}
}
