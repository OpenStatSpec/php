<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

/**
 * Normalises database-driver scalar representations at the test boundary.
 *
 * PDO drivers legitimately return integer catalogue fields either as native ints
 * or numeric strings. The OpenStatSpec catalogue contract is numeric; this
 * assertion keeps server-integration coverage focused on that contract while
 * preserving strict checks for the textual fields.
 */
trait VariableCatalogAssertions
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    private function assertVariableCatalog(array $rows): void
    {
        $normalised = array_map(
            static function (array $row): array {
                $ordinal = $row['ordinal'] ?? null;
                if (!is_int($ordinal) && !(is_string($ordinal) && ctype_digit($ordinal))) {
                    self::fail('Variable catalogue ordinal must be an integer value.');
                }

                return [
                    'ordinal' => (int) $ordinal,
                    'source_name' => $row['source_name'] ?? null,
                    'storage_kind' => $row['storage_kind'] ?? null,
                ];
            },
            $rows,
        );

        self::assertSame(
            [
                ['ordinal' => 1, 'source_name' => 'Score', 'storage_kind' => 'numeric'],
                ['ordinal' => 2, 'source_name' => 'Reason', 'storage_kind' => 'string'],
                ['ordinal' => 3, 'source_name' => 'LongText', 'storage_kind' => 'string'],
            ],
            $normalised,
        );
    }
}
