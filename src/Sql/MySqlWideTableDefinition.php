<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

/** A deterministic MySQL/MariaDB native-table DDL plan. */
final readonly class MySqlWideTableDefinition
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
