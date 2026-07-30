<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Sql;

use OpenStatSpec\Sql\MySqlIndexIntrospection;
use PHPUnit\Framework\TestCase;

final class MySqlIndexIntrospectionTest extends TestCase
{
    public function testNormalizesUppercaseAndMixedCaseDoltResultKeysWithoutChangingColumnOrder(): void
    {
        self::assertSame([
            'uq_dataset_ordinal' => ['dataset_id', 'source_ordinal'],
        ], MySqlIndexIntrospection::uniqueColumnLists([
            ['INDEX_NAME' => 'uq_dataset_ordinal', 'NON_UNIQUE' => 0, 'COLUMN_NAME' => 'dataset_id'],
            ['Index_Name' => 'uq_dataset_ordinal', 'Non_Unique' => '0', 'Column_Name' => 'source_ordinal'],
            ['INDEX_NAME' => 'ignored_non_unique', 'NON_UNIQUE' => 1, 'COLUMN_NAME' => 'dataset_id'],
        ]));
    }
}
