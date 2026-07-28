<?php

declare(strict_types=1);

namespace OpenStatSpec\Core;

use JsonSerializable;
use OpenStatSpec\Spss\SpssEngine;
use OpenStatSpec\Sql\MySqlProfile;
use OpenStatSpec\Sql\PdoSqlProfile;
use OpenStatSpec\Sql\PostgreSqlProfile;
use OpenStatSpec\Sql\SqliteProfile;

/** Machine-readable SPSS 1.0 and SQL-profile capability declaration. */
final readonly class CapabilityDeclaration implements JsonSerializable
{
    public function __construct(private SpssEngine $engine) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $mysql = new MySqlProfile();

        return [
            'specification' => 'OpenStatSpec',
            'profile' => 'SPSS SAV/ZSAV 1.0',
            'directions' => ['import', 'export', 'semantic_round_trip'],
            'required_capabilities' => array_fill_keys([
                'sav_read', 'sav_write', 'zsav_read', 'zsav_write',
                'file_label', 'documents', 'source_encoding', 'attributes',
                'variable_dictionary', 'value_labels', 'missing_rules',
                'lowest_highest_missing', 'long_utf8_strings', 'weight_variable',
                'variable_sets', 'multiple_response_sets',
                'multiple_response_string_counted_value',
            ], true),
            'engine' => $this->engine->identity(),
            'sql_profiles' => [
                'sqlite' => $this->profile(new SqliteProfile()),
                'mysql' => $this->profile($mysql),
                'mariadb' => array_merge($this->profile($mysql), ['driver' => 'mysql']),
                'postgresql' => $this->profile(new PostgreSqlProfile()),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<string, int|string|bool> */
    private function profile(PdoSqlProfile $profile): array
    {
        return [
            'driver' => $profile->driverName(),
            'server_version_range' => $profile->serverVersionRange(),
            'maximum_physical_columns' => $profile->maximumSourceVariables() + 1,
            'maximum_source_variables' => $profile->maximumSourceVariables(),
            'maximum_identifier_bytes' => $profile->identifierLimit(),
            'maximum_value_bytes' => $profile->maximumValueBytes(),
            'maximum_row_bytes' => $profile->maximumRowBytes(),
            'numeric_type' => $profile->numericType(),
            'text_type' => $profile->textType(),
            'ddl_atomic' => $profile->ddlAtomic(),
            'failure_cleanup' => $profile->ddlAtomic() ? 'transaction_rollback' : 'compensating_cleanup',
            'physical_table_mapping' => 'dataset.physical_table_schema + dataset.physical_table_name',
            'identifier_policy' => 'deterministic_safe_mapping; source name remains authoritative',
        ];
    }
}
