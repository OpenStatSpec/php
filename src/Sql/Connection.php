<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;

final readonly class Connection
{
    public PdoSqlProfile $profile;

    public function __construct(public PDO $pdo)
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!is_string($driver)) {
            throw new UnsupportedOperation(
                DiagnosticCode::UnsupportedSqlDriver,
                'The PDO connection did not report a usable SQL driver name.',
            );
        }

        $this->profile = match ($driver) {
            'sqlite' => new SqliteProfile(),
            'pgsql' => new PostgreSqlProfile(),
            'mysql' => new MySqlProfile(),
            default => throw new UnsupportedOperation(
                DiagnosticCode::UnsupportedSqlDriver,
                sprintf('The PDO driver "%s" has no OpenStatSpec SQL profile.', $driver),
            ),
        };
    }
}
