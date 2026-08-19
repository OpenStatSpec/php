<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\ServerVersionPolicy;
use PDO;
use PDOException;

class MySqlProfile extends AbstractPdoSqlProfile
{
    private ?bool $lowerCaseTableNames = null;

    public function driverName(): string
    {
        return 'mysql';
    }
    public function maximumSourceVariables(): int
    {
        return 1016;
    }
    public function maximumValueBytes(): int
    {
        return 4_294_967_295;
    }
    public function maximumRowBytes(): int
    {
        return 65_535;
    }
    public function serverVersionRange(): string
    {
        return ServerVersionPolicy::claim('mysql') . ' or ' . ServerVersionPolicy::claim('mariadb');
    }
    public function ddlAtomic(): bool
    {
        return false;
    }
    protected function rowStorageBytes(string $kind, int $declaredWidth): int
    {
        return $kind === 'string' ? 20 : 8;
    }
    public function identifierLimit(): int
    {
        return 64;
    }
    public function identifierLimitUnit(): string
    {
        return 'characters';
    }

    public function identifierLimitSource(): string
    {
        return 'MySQL/MariaDB native identifier limit';
    }
    public function quoteIdentifier(string $identifier): string
    {
        return $this->quoteWith($identifier, "`");
    }
    public function numericType(): string
    {
        return 'DOUBLE';
    }
    public function textType(): string
    {
        return 'LONGTEXT';
    }
    public function exactValueCondition(string $expression, bool $stringValue): string
    {
        return $stringValue
            ? 'BINARY ' . $expression . ' = BINARY ?'
            : parent::exactValueCondition($expression, false);
    }
    public function effectiveMaximumValueBytes(PDO $pdo): int
    {
        $packet = $this->packetPayloadBytes($pdo);
        return $packet === null ? $this->maximumValueBytes() : min($this->maximumValueBytes(), $packet);
    }
    public function effectiveMaximumRowBytes(PDO $pdo): int
    {
        return $this->maximumRowBytes();
    }
    public function effectiveMaximumStatementBytes(PDO $pdo): int
    {
        return $this->packetPayloadBytes($pdo) ?? $this->maximumValueBytes();
    }
    public function effectiveLimitSources(PDO $pdo): array
    {
        $packetSource = $this->packetPayloadBytes($pdo) === null
            ? 'profile_theoretical_limit; @@max_allowed_packet unavailable'
            : 'active @@max_allowed_packet worst-case payload: (packet - 131072) / 2';
        return [
            'maximum_source_variables' => 'InnoDB/profile column limit',
            'maximum_value_bytes' => 'min(LONGTEXT, ' . $packetSource . ')',
            'maximum_row_bytes' => 'InnoDB physical row limit with off-page text references',
            'maximum_statement_bytes' => $packetSource,
            'identifier_limit' => 'server identifier limit',
        ];
    }
    private function packetPayloadBytes(PDO $pdo): ?int
    {
        $statement = $pdo->query('SELECT @@max_allowed_packet');
        $packet = $statement === false ? false : $statement->fetchColumn();
        if (!is_int($packet) && !is_string($packet)) {
            return null;
        }
        // Reserve enough SQL/identifier overhead for the maximum column count
        // and halve the remainder for worst-case emulated-prepare escaping.
        return intdiv(max(0, (int) $packet - 131_072), 2);
    }

    /**
     * Reports whether the server treats physical table names case-insensitively
     * (lower_case_table_names=1 or =2). Cached per profile instance because the
     * setting is server-static for the lifetime of the connection.
     */
    public function lowerCaseTableNames(PDO $pdo): bool
    {
        if ($this->lowerCaseTableNames !== null) {
            return $this->lowerCaseTableNames;
        }
        try {
            $statement = $pdo->query('SELECT @@lower_case_table_names');
            $value = $statement === false ? false : $statement->fetchColumn();
        } catch (PDOException) {
            $value = false;
        }
        return $this->lowerCaseTableNames = is_numeric($value) && (int) $value !== 0;
    }
}
