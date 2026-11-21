<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Sql;

use OpenStatSpec\Sql\NormativeCatalog;
use PHPUnit\Framework\TestCase;

final class NormativeCatalogUuidTest extends TestCase
{
    public function testGeneratedIdsAreVersionFourWithTheVariantTheExecutorAccepts(): void
    {
        for ($i = 0; $i < 500; $i++) {
            self::assertMatchesRegularExpression(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                NormativeCatalog::uuid(),
            );
        }
    }
}
