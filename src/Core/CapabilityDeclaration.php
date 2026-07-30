<?php

declare(strict_types=1);

namespace OpenStatSpec\Core;

use JsonSerializable;
use OpenStatSpec\Spss\SpssEngine;
use OpenStatSpec\Sql\CatalogOwnership;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Sql\DoltProfile;
use OpenStatSpec\Sql\MySqlProfile;
use OpenStatSpec\Sql\PdoSqlProfile;
use OpenStatSpec\Sql\PostgreSqlProfile;
use OpenStatSpec\Sql\SqliteProfile;
use PDO;

/** Machine-readable SPSS 1.0 and SQL-profile capability declaration. */
final readonly class CapabilityDeclaration implements JsonSerializable
{
    public const SPECIFICATION_RELEASE = null;
    public const SPECIFICATION_COMMIT = '34141dda023d9e0217c37c232e39f436edfb0746';

    private Connection $connection;

    public function __construct(PDO|Connection $connection, private SpssEngine $engine)
    {
        $this->connection = $connection instanceof Connection ? $connection : new Connection($connection);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $mysql = new MySqlProfile();
        $pdo = $this->connection->pdo;
        $serverVersion = $this->connection->serverVersion;
        $activeProfile = $this->connection->profileName;
        $serverAssessment = ServerVersionPolicy::assess($activeProfile, $serverVersion);

        return [
            'specification' => 'OpenStatSpec',
            'specification_status' => 'release_candidate',
            'specification_release' => self::SPECIFICATION_RELEASE,
            'specification_commit' => self::SPECIFICATION_COMMIT,
            'profile' => 'SPSS SAV/ZSAV 1.0',
            'directions' => ['import', 'export', 'semantic_round_trip'],
            'required_capabilities' => $this->engine->capabilities(),
            'engine' => $this->engine->identity(),
            'resource_behavior' => [
                'streaming_import' => false,
                'streaming_export' => false,
                'buffering' => 'fully_buffered',
                'maximum_cases' => null,
                'maximum_source_file_bytes' => null,
                'limit_basis' => 'runtime_memory_limit',
            ],
            'active_connection' => [
                'profile' => $activeProfile,
                'server_version' => $serverVersion,
                'raw_server_version' => $this->connection->rawServerVersion,
                'identity_source' => $this->connection->identitySource,
                'identity_probe_results' => $this->connection->identityProbeResults,
                'claimed_supported' => $serverAssessment['claimed_supported'],
                'matched_claim' => $serverAssessment['matched_claim'],
                'catalog_binding' => CatalogOwnership::binding($pdo),
            ],
            'sql_profiles' => [
                'sqlite' => $this->profile('sqlite', new SqliteProfile(), $activeProfile === 'sqlite'),
                'mysql' => $this->profile('mysql', $mysql, $activeProfile === 'mysql'),
                'mariadb' => $this->profile('mariadb', $mysql, $activeProfile === 'mariadb'),
                'dolt' => $this->profile('dolt', new DoltProfile(), $activeProfile === 'dolt'),
                'postgresql' => $this->profile('postgresql', new PostgreSqlProfile(), $activeProfile === 'postgresql'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<string, mixed> */
    private function profile(string $name, PdoSqlProfile $profile, bool $active): array
    {
        $identifierLimit = $this->identifierLimit($profile);
        $declared = [
            'maximum_physical_columns' => $profile->maximumSourceVariables() + 1,
            'maximum_source_variables' => $profile->maximumSourceVariables(),
            'identifier_limit' => $identifierLimit,
            'maximum_value_bytes' => $profile->maximumValueBytes(),
            'maximum_row_bytes' => $profile->maximumRowBytes(),
        ];
        $theoretical = $name === 'dolt'
            ? ['maximum_value_bytes' => $profile->maximumValueBytes()]
            : $declared;
        $proposed = $name === 'dolt' ? [
            'maximum_physical_columns' => $declared['maximum_physical_columns'],
            'maximum_source_variables' => $declared['maximum_source_variables'],
            'maximum_row_bytes' => $declared['maximum_row_bytes'],
        ] : null;
        $observedLimits = $name === 'dolt' ? [
            'minimum_observed_physical_columns' => 307,
            'identifier_limit' => $identifierLimit,
            'rejected_identifier_bytes' => 65,
        ] : null;

        $effective = null;
        if ($active) {
            $effectiveVariables = $profile->effectiveMaximumSourceVariables($this->connection->pdo);
            $effective = [
                'maximum_physical_columns' => $effectiveVariables + 1,
                'maximum_source_variables' => $effectiveVariables,
                'identifier_limit' => $identifierLimit,
                'maximum_value_bytes' => $profile->effectiveMaximumValueBytes($this->connection->pdo),
                'maximum_row_bytes' => $profile->effectiveMaximumRowBytes($this->connection->pdo),
                'maximum_statement_bytes' => $profile->effectiveMaximumStatementBytes($this->connection->pdo),
                'sources' => $profile->effectiveLimitSources($this->connection->pdo),
            ];
        }

        $sources = $effective['sources'] ?? [];
        $hasActiveObservation = array_filter(
            $sources,
            static fn(string $source): bool => str_contains($source, 'active '),
        ) !== [];
        $compileTimeCeiling = array_filter(
            $sources,
            static fn(string $source): bool => str_contains($source, 'compile-time '),
        ) !== [];

        return [
            'profile' => $name,
            'engine' => $name,
            'dialect' => $name === 'dolt' ? 'mysql' : $name,
            'transport' => $name === 'dolt' ? 'mysql_compatible' : $name,
            'specification_commit' => self::SPECIFICATION_COMMIT,
            'specification_status' => 'release_candidate',
            'specification_release' => self::SPECIFICATION_RELEASE,
            'driver' => $name === 'postgresql' ? 'pgsql' : ($name === 'mariadb' ? 'mysql' : $profile->driverName()),
            'identity' => $name === 'dolt' ? [
                'required_probes' => ['@@version', '@@version_comment', 'DOLT_VERSION()'],
                'version_comment_normalized_equals' => 'dolt',
                'signals_must_be_mutually_consistent' => true,
                'failure_policy' => 'fail_before_catalog_or_dataset_mutation',
                'active_probe_results' => $active ? $this->connection->identityProbeResults : null,
            ] : null,
            'claimed_server_versions' => ServerVersionPolicy::claim($name),
            'claimed_version_range' => $name === 'dolt' ? [
                'minimum_inclusive' => '2.2.2',
                'maximum_inclusive' => '2.2.2',
            ] : null,
            'ci_tested_server_versions' => match ($name) {
                'mysql' => ['MySQL 8.4.x', 'MySQL 9.7.x'],
                'mariadb' => ['MariaDB 11.4.x', 'MariaDB 11.8.x', 'MariaDB 12.3.x'],
                'dolt' => ['Dolt 2.2.2'],
                'postgresql' => ['PostgreSQL 17.x', 'PostgreSQL 18.x'],
                default => ['active PDO SQLite version reported by CI'],
            },
            'exact_ci_tested_versions' => $name === 'dolt' ? ['2.2.2'] : null,
            'theoretical_limits' => $theoretical,
            'proposed_adapter_limits' => $proposed,
            'observed_limits' => $observedLimits,
            'effective_limits' => $effective,
            'effective_limits_status' => !$active
                ? 'not_connected'
                : ($hasActiveObservation ? 'active_connection_mixed' : ($compileTimeCeiling ? 'compile_time_ceiling' : 'profile_theoretical_fallback')),
            'numeric_type' => $profile->numericType(),
            'text_type' => $profile->textType(),
            'ddl_atomic' => $profile->ddlAtomic(),
            'failure_cleanup' => $profile->ddlAtomic() ? 'transaction_rollback' : 'compensating_cleanup',
            'limit_bases' => [
                'maximum_physical_columns' => $name === 'dolt' ? 'proposed_adapter_envelope' : 'theoretical_engine_limit',
                'maximum_source_variables' => $name === 'dolt' ? 'proposed_adapter_envelope' : 'theoretical_engine_limit',
                'identifier_limit' => $name === 'dolt' ? 'observed_exact_version' : 'theoretical_engine_limit',
                'maximum_value_bytes' => 'theoretical_engine_limit',
                'maximum_row_bytes' => $name === 'dolt' ? 'proposed_adapter_envelope' : 'theoretical_engine_limit',
                'maximum_statement_bytes' => 'active_connection_observation',
            ],
            'numeric_exception_policy' => $name === 'dolt' ? [
                'nan' => 'reject_before_mutation',
                'positive_infinity' => 'reject_before_mutation',
                'negative_infinity' => 'reject_before_mutation',
                'system_missing' => 'sql_null',
            ] : null,
            'storage_evidence' => $name === 'dolt' ? [
                'binary64' => [
                    'type' => 'DOUBLE',
                    'classification' => 'observed_exact_version',
                    'source' => 'Dolt 2.2.2 interoperability verification',
                    'version' => '2.2.2',
                    'maximum_finite_round_trip_exact' => true,
                    'non_finite_policy' => 'reject_before_mutation',
                ],
                'text' => [
                    'type' => 'LONGTEXT NOT NULL',
                    'classification' => 'observed_exact_version',
                    'source' => 'Dolt 2.2.2 interoperability verification',
                    'version' => '2.2.2',
                    'observed_value_bytes' => 65_504,
                    'unit' => 'bytes',
                ],
            ] : null,
            'transformation_workflow' => $name === 'dolt' ? 'unsupported' : null,
            'physical_table_mapping' => 'dataset.physical_table_schema + dataset.physical_table_name',
            'identifier_policy' => 'deterministic ASCII mapping; source name remains authoritative',
        ];
    }

    /** @return array{value: int, unit: string, source: string, repertoire: string} */
    private function identifierLimit(PdoSqlProfile $profile): array
    {
        return [
            'value' => $profile->identifierLimit(),
            'unit' => $profile->identifierLimitUnit(),
            'source' => $profile->identifierLimitSource(),
            'repertoire' => $profile->generatedIdentifierRepertoire(),
        ];
    }
}
