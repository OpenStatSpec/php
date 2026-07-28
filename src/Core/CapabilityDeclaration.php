<?php

declare(strict_types=1);

namespace OpenStatSpec\Core;

use JsonSerializable;
use OpenStatSpec\Spss\SpssEngine;
use OpenStatSpec\Sql\MySqlProfile;
use OpenStatSpec\Sql\PdoSqlProfile;
use OpenStatSpec\Sql\PostgreSqlProfile;
use OpenStatSpec\Sql\SqliteProfile;
use PDO;

/** Machine-readable SPSS 1.0 and SQL-profile capability declaration. */
final readonly class CapabilityDeclaration implements JsonSerializable
{
    public const SPECIFICATION_RELEASE = null;
    public const SPECIFICATION_COMMIT = '04d3c3c3fdcf621165f312319d25a448db387370';

    public function __construct(private PDO $pdo, private SpssEngine $engine) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $mysql = new MySqlProfile();
        $serverVersion = (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $activeProfile = $this->activeProfile($serverVersion);

        return [
            'specification' => 'OpenStatSpec',
            'specification_release' => self::SPECIFICATION_RELEASE,
            'specification_commit' => self::SPECIFICATION_COMMIT,
            'profile' => 'SPSS SAV/ZSAV 1.0',
            'directions' => ['import', 'export', 'semantic_round_trip'],
            'required_capabilities' => $this->engine->capabilities(),
            'engine' => $this->engine->identity(),
            'active_connection' => [
                'profile' => $activeProfile,
                'server_version' => $serverVersion,
            ],
            'sql_profiles' => [
                'sqlite' => $this->profile('sqlite', new SqliteProfile(), $activeProfile === 'sqlite'),
                'mysql' => $this->profile('mysql', $mysql, $activeProfile === 'mysql'),
                'mariadb' => $this->profile('mariadb', $mysql, $activeProfile === 'mariadb'),
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
        $theoretical = [
            'maximum_physical_columns' => $profile->maximumSourceVariables() + 1,
            'maximum_source_variables' => $profile->maximumSourceVariables(),
            'maximum_identifier_bytes' => $profile->identifierLimit(),
            'maximum_value_bytes' => $profile->maximumValueBytes(),
            'maximum_row_bytes' => $profile->maximumRowBytes(),
        ];
        $effective = null;
        if ($active) {
            $effectiveVariables = $profile->effectiveMaximumSourceVariables($this->pdo);
            $effective = [
                'maximum_physical_columns' => $effectiveVariables + 1,
                'maximum_source_variables' => $effectiveVariables,
                'maximum_identifier_bytes' => $profile->identifierLimit(),
                'maximum_value_bytes' => $profile->effectiveMaximumValueBytes($this->pdo),
                'maximum_row_bytes' => $profile->effectiveMaximumRowBytes($this->pdo),
                'maximum_statement_bytes' => $profile->effectiveMaximumStatementBytes($this->pdo),
                'sources' => $profile->effectiveLimitSources($this->pdo),
            ];
        }

        $sources = $effective['sources'] ?? [];
        $observed = array_filter(
            $sources,
            static fn(string $source): bool => str_contains($source, 'active '),
        ) !== [];

        return [
            'driver' => $name === 'postgresql' ? 'pgsql' : ($name === 'mariadb' ? 'mysql' : $profile->driverName()),
            'claimed_server_versions' => match ($name) {
                'mysql' => 'MySQL 8.4.x',
                'mariadb' => 'MariaDB 11.4.x',
                default => $profile->serverVersionRange(),
            },
            'ci_tested_server_versions' => match ($name) {
                'mysql' => ['MySQL 8.4.x'],
                'mariadb' => ['MariaDB 11.4.x'],
                'postgresql' => ['PostgreSQL 17.x'],
                default => ['active PDO SQLite version reported by CI'],
            },
            'theoretical_limits' => $theoretical,
            'effective_limits' => $effective,
            'effective_limits_status' => !$active
                ? 'not_connected'
                : ($observed ? 'active_connection_mixed' : 'profile_theoretical_fallback'),
            'numeric_type' => $profile->numericType(),
            'text_type' => $profile->textType(),
            'ddl_atomic' => $profile->ddlAtomic(),
            'failure_cleanup' => $profile->ddlAtomic() ? 'transaction_rollback' : 'compensating_cleanup',
            'physical_table_mapping' => 'dataset.physical_table_schema + dataset.physical_table_name',
            'identifier_policy' => 'deterministic_safe_mapping; source name remains authoritative',
        ];
    }

    private function activeProfile(string $serverVersion): string
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return match ($driver) {
            'sqlite' => 'sqlite',
            'pgsql' => 'postgresql',
            'mysql' => stripos($serverVersion, 'mariadb') !== false ? 'mariadb' : 'mysql',
            default => throw new UnsupportedOperation(DiagnosticCode::UnsupportedSqlDriver, 'The active connection has no capability profile.'),
        };
    }
}
