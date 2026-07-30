<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use PDO;

/** Dolt's supported MySQL-wire SQL profile; SQL mechanics reuse MySqlProfile. */
final class DoltProfile extends MySqlProfile
{
    public function maximumSourceVariables(): int
    {
        return 305;
    }

    public function maximumRowBytes(): int
    {
        return 65_504;
    }

    public function serverVersionRange(): string
    {
        return 'Dolt 2.2.2';
    }

    public function identifierLimitUnit(): string
    {
        return 'bytes';
    }

    public function identifierLimitSource(): string
    {
        return 'Dolt 2.2.2 64-byte identifier limit; generated identifiers are ASCII';
    }

    public function effectiveLimitSources(PDO $pdo): array
    {
        $mysqlSources = parent::effectiveLimitSources($pdo);
        $packetSource = str_contains($mysqlSources['maximum_statement_bytes'], 'active @@max_allowed_packet')
            ? $mysqlSources['maximum_statement_bytes']
            : '@@max_allowed_packet unavailable; conservative adapter fallback';

        return [
            'maximum_source_variables' => 'Dolt 2.2.2 validated adapter envelope: 305 source variables (306 physical columns including __case_ordinal)',
            'maximum_value_bytes' => 'Dolt 2.2.2 LONGTEXT type limit constrained by ' . $packetSource,
            'maximum_row_bytes' => 'Dolt 2.2.2 65,504-byte tuple-data preflight; LONGTEXT uses a 20-byte out-of-band address',
            'maximum_statement_bytes' => $packetSource,
            'identifier_limit' => $this->identifierLimitSource(),
        ];
    }
}
