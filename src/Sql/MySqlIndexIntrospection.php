<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

/** Normalizes MySQL-family index metadata across PDO server implementations. */
final class MySqlIndexIntrospection
{
    /**
     * @param iterable<array<array-key, mixed>> $rows
     * @return array<string, list<string>>
     */
    public static function uniqueColumnLists(iterable $rows): array
    {
        $uniqueIndexes = [];
        foreach ($rows as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            if ((int) ($row['non_unique'] ?? 1) !== 0
                || !is_string($row['index_name'] ?? null)
                || !is_string($row['column_name'] ?? null)
            ) {
                continue;
            }
            $uniqueIndexes[$row['index_name']][] = $row['column_name'];
        }

        return $uniqueIndexes;
    }
}
