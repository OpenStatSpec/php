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
        $pdo = new \PDO('sqlite::memory:');
        $declaration = (new CapabilityDeclaration($pdo, new PhpSpssEngine()))->toArray();
        self::assertSame(CapabilityDeclaration::SPECIFICATION_RELEASE, $declaration['specification_release']);
        self::assertSame(CapabilityDeclaration::SPECIFICATION_COMMIT, $declaration['specification_commit']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $declaration['specification_commit']);
        self::assertSame(['import', 'export', 'semantic_round_trip'], $declaration['directions']);
        self::assertSame('sqlite', $declaration['active_connection']['profile']);
        self::assertNotSame('', $declaration['active_connection']['server_version']);
        self::assertNotEmpty($declaration['required_capabilities']);
        foreach ($declaration['required_capabilities'] as $supported) {
            self::assertTrue($supported);
        }
        foreach (['sqlite', 'mysql', 'mariadb', 'postgresql'] as $name) {
            $profile = $declaration['sql_profiles'][$name];
            self::assertGreaterThan(0, $profile['theoretical_limits']['maximum_physical_columns']);
            self::assertGreaterThan(0, $profile['theoretical_limits']['maximum_value_bytes']);
            self::assertGreaterThan(0, $profile['theoretical_limits']['maximum_row_bytes']);
            self::assertNotSame('', $profile['claimed_server_versions']);
            self::assertNotEmpty($profile['ci_tested_server_versions']);
            self::assertNotSame('', $profile['physical_table_mapping']);
            if ($name === 'sqlite') {
                self::assertSame('active_connection_mixed', $profile['effective_limits_status']);
                self::assertGreaterThan(0, $profile['effective_limits']['maximum_source_variables']);
                self::assertGreaterThan(0, $profile['effective_limits']['maximum_statement_bytes']);
                self::assertNotEmpty($profile['effective_limits']['sources']);
            } else {
                self::assertSame('not_connected', $profile['effective_limits_status']);
                self::assertNull($profile['effective_limits']);
            }
        }
        self::assertIsArray(json_decode(json_encode($declaration, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    }
}
