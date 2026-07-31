<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Core;

use OpenStatSpec\Core\CapabilityDeclaration;
use OpenStatSpec\Core\ServerVersionPolicy;
use OpenStatSpec\Spss\PhpSpssEngine;
use PHPUnit\Framework\TestCase;

final class CapabilityDeclarationTest extends TestCase
{
    public function testDeclarationIsMachineReadableAndIncludesEveryProfileLimit(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $declaration = (new CapabilityDeclaration($pdo, new PhpSpssEngine()))->toArray();
        self::assertSame('release_candidate', $declaration['specification_status']);
        self::assertNull($declaration['specification_release']);
        self::assertSame(CapabilityDeclaration::SPECIFICATION_RELEASE, $declaration['specification_release']);
        self::assertSame('e94ae8349d2b0dffe0c65e820b4b22b8c074b7b5', $declaration['specification_commit']);
        self::assertSame(CapabilityDeclaration::SPECIFICATION_COMMIT, $declaration['specification_commit']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $declaration['specification_commit']);
        self::assertSame(['import', 'export', 'semantic_round_trip'], $declaration['directions']);
        self::assertSame('sqlite', $declaration['active_connection']['profile']);
        self::assertNotSame('', $declaration['active_connection']['server_version']);
        self::assertSame($declaration['active_connection']['server_version'], $declaration['active_connection']['raw_server_version']);
        self::assertSame('PDO::ATTR_SERVER_VERSION', $declaration['active_connection']['identity_source']);
        self::assertSame(
            ['PDO::ATTR_SERVER_VERSION' => $declaration['active_connection']['server_version']],
            $declaration['active_connection']['identity_probe_results'],
        );
        self::assertTrue($declaration['active_connection']['claimed_supported']);
        self::assertSame('SQLite >=3.24.0 <4.0.0', $declaration['active_connection']['matched_claim']);
        self::assertTrue($declaration['active_connection']['catalog_binding']['exclusive_namespace_verified']);
        self::assertSame('verified_exclusive_runtime_inventory', $declaration['active_connection']['catalog_binding']['verification_status']);
        self::assertSame('sqlite_database', $declaration['active_connection']['catalog_binding']['mode']);
        self::assertSame('main', $declaration['active_connection']['catalog_binding']['name']);
        self::assertTrue($declaration['active_connection']['catalog_binding']['exclusive_namespace_required']);
        self::assertSame('catalog_identity', $declaration['active_connection']['catalog_binding']['identity_marker']);
        self::assertSame([
            'streaming_import' => false,
            'streaming_export' => false,
            'buffering' => 'fully_buffered',
            'maximum_cases' => null,
            'maximum_source_file_bytes' => null,
            'limit_basis' => 'runtime_memory_limit',
        ], $declaration['resource_behavior']);
        self::assertSame('3.0.0', $declaration['engine']['active_version']);
        self::assertSame('>=3.0.0 <4.0.0', $declaration['engine']['claimed_version_range']);
        self::assertSame(['3.0.0'], $declaration['engine']['ci_tested_versions']);
        self::assertTrue($declaration['engine']['claimed_supported']);
        self::assertNotEmpty($declaration['required_capabilities']);
        foreach ($declaration['required_capabilities'] as $supported) {
            self::assertTrue($supported);
        }
        self::assertArrayNotHasKey('mssql', $declaration['sql_profiles']);
        foreach (['sqlite', 'mysql', 'mariadb', 'dolt', 'postgresql'] as $name) {
            $profile = $declaration['sql_profiles'][$name];
            self::assertSame($name, $profile['profile']);
            self::assertSame(CapabilityDeclaration::SPECIFICATION_COMMIT, $profile['specification_commit']);
            self::assertSame('release_candidate', $profile['specification_status']);
            self::assertSame(CapabilityDeclaration::SPECIFICATION_RELEASE, $profile['specification_release']);
            self::assertGreaterThan(0, $profile['theoretical_limits']['maximum_value_bytes']);
            self::assertNotSame('', $profile['claimed_server_versions']);
            self::assertNotEmpty($profile['ci_tested_server_versions']);
            self::assertSame(
                ServerVersionPolicy::ciTestedVersions($name),
                $profile['ci_tested_server_versions'],
            );
            self::assertNotSame('', $profile['physical_table_mapping']);
            if ($name === 'dolt') {
                self::assertSame(['maximum_value_bytes'], array_keys($profile['theoretical_limits']));
                self::assertSame(306, $profile['proposed_adapter_limits']['maximum_physical_columns']);
                self::assertSame(305, $profile['proposed_adapter_limits']['maximum_source_variables']);
                self::assertSame(65_504, $profile['proposed_adapter_limits']['maximum_row_bytes']);
                self::assertSame(307, $profile['observed_limits']['minimum_observed_physical_columns']);
                self::assertSame(64, $profile['observed_limits']['identifier_limit']['value']);
                self::assertSame(65, $profile['observed_limits']['rejected_identifier_bytes']);
                self::assertSame('observed_exact_version', $profile['limit_bases']['identifier_limit']);
                self::assertTrue($profile['storage_evidence']['binary64']['maximum_finite_round_trip_exact']);
                self::assertSame('reject_before_mutation', $profile['storage_evidence']['binary64']['non_finite_policy']);
                self::assertSame([
                    'nan' => 'reject_before_mutation',
                    'positive_infinity' => 'reject_before_mutation',
                    'negative_infinity' => 'reject_before_mutation',
                    'system_missing' => 'sql_null',
                ], $profile['numeric_exception_policy']);
                self::assertSame(65_504, $profile['storage_evidence']['text']['observed_value_bytes']);
                self::assertSame('unsupported', $profile['transformation_workflow']);
                self::assertSame('mysql_compatible', $profile['transport']);
                self::assertSame(['2.2.2'], $profile['exact_ci_tested_versions']);
                self::assertSame(
                    ['@@version', '@@version_comment', 'DOLT_VERSION()'],
                    $profile['identity']['required_probes'],
                );
                self::assertNull($profile['identity']['active_probe_results']);
            } else {
                self::assertGreaterThan(0, $profile['theoretical_limits']['maximum_physical_columns']);
                self::assertGreaterThan(0, $profile['theoretical_limits']['maximum_row_bytes']);
                self::assertArrayNotHasKey('maximum_identifier_bytes', $profile['theoretical_limits']);
                self::assertGreaterThan(0, $profile['theoretical_limits']['identifier_limit']['value']);
                self::assertSame(
                    in_array($name, ['mysql', 'mariadb'], true) ? 'characters' : 'bytes',
                    $profile['theoretical_limits']['identifier_limit']['unit'],
                );
                self::assertNotSame('', $profile['theoretical_limits']['identifier_limit']['source']);
                self::assertSame('ASCII lowercase letters, digits, and underscore', $profile['theoretical_limits']['identifier_limit']['repertoire']);
            }
            if ($name === 'sqlite') {
                self::assertSame('compile_time_ceiling', $profile['effective_limits_status']);
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
