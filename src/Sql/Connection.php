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
    public string $rawServerVersion;
    public string $identitySource;
    /** @var array<string, string|null> */
    public array $identityProbeResults;
    public bool $claimedSupported;
    public ?string $matchedClaim;

    public function __construct(public PDO $pdo)
    {
        $identity = ServerIdentity::detect($pdo);
        $this->profileName = $identity->profileName;
        $this->serverVersion = $identity->serverVersion;
        $this->rawServerVersion = $identity->rawServerVersion;
        $this->identitySource = $identity->identitySource;
        $this->identityProbeResults = $identity->probeResults;
        $this->profile = match ($this->profileName) {
            'sqlite' => new SqliteProfile(),
            'postgresql' => new PostgreSqlProfile(),
            'mysql', 'mariadb' => new MySqlProfile(),
            'dolt' => new DoltProfile(),
            default => throw new UnsupportedOperation(
                DiagnosticCode::UnsupportedSqlDriver,
                sprintf('The SQL profile "%s" has no OpenStatSpec implementation.', $this->profileName),
            ),
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
