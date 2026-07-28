<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\ServerVersionPolicy;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;

final readonly class Connection
{
    public PdoSqlProfile $profile;
    public string $profileName;
    public string $serverVersion;
    public bool $claimedSupported;
    public ?string $matchedClaim;

    public function __construct(public PDO $pdo)
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!is_string($driver)) {
            throw new UnsupportedOperation(
                DiagnosticCode::UnsupportedSqlDriver,
                'The PDO connection did not report a usable SQL driver name.',
            );
        }

        $this->serverVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $this->profileName = match ($driver) {
            'sqlite' => 'sqlite',
            'pgsql' => 'postgresql',
            'mysql' => stripos($this->serverVersion, 'mariadb') !== false ? 'mariadb' : 'mysql',
            default => throw new UnsupportedOperation(
                DiagnosticCode::UnsupportedSqlDriver,
                sprintf('The PDO driver "%s" has no OpenStatSpec SQL profile.', $driver),
            ),
        };
        $this->profile = match ($this->profileName) {
            'sqlite' => new SqliteProfile(),
            'postgresql' => new PostgreSqlProfile(),
            'mysql', 'mariadb' => new MySqlProfile(),
        };
        $assessment = ServerVersionPolicy::assess($this->profileName, $this->serverVersion);
        $this->claimedSupported = $assessment['claimed_supported'];
        $this->matchedClaim = $assessment['matched_claim'];
    }

    public function assertClaimedSupported(): void
    {
        if (!$this->claimedSupported) {
            throw new UnsupportedOperation(
                DiagnosticCode::TargetCapabilityExceeded,
                sprintf(
                    'The active %s server version "%s" is outside the claimed profile %s.',
                    $this->profileName,
                    $this->serverVersion,
                    ServerVersionPolicy::claim($this->profileName),
                ),
            );
        }
    }
}
