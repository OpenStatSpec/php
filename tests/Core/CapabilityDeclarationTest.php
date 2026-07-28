<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Core;

use OpenStatSpec\Core\CapabilityDeclaration;
use OpenStatSpec\Spss\PhpSpssEngine;
use PHPUnit\Framework\TestCase;

final class CapabilityDeclarationTest extends TestCase
{
    public function testDeclarationIsMachineReadableAndIncludesEveryProfileLimit(): void
    {
        $declaration = (new CapabilityDeclaration(new PhpSpssEngine()))->toArray();
        self::assertSame(['import', 'export', 'semantic_round_trip'], $declaration['directions']);
        self::assertNotEmpty($declaration['required_capabilities']);
        foreach ($declaration['required_capabilities'] as $supported) {
            self::assertTrue($supported);
        }
        foreach (['sqlite', 'mysql', 'mariadb', 'postgresql'] as $name) {
            $profile = $declaration['sql_profiles'][$name];
            self::assertGreaterThan(0, $profile['maximum_physical_columns']);
            self::assertGreaterThan(0, $profile['maximum_value_bytes']);
            self::assertGreaterThan(0, $profile['maximum_row_bytes']);
            self::assertNotSame('', $profile['server_version_range']);
            self::assertNotSame('', $profile['physical_table_mapping']);
        }
        self::assertIsArray(json_decode(json_encode($declaration, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }
}
