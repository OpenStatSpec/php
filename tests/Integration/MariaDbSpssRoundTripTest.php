<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

/**
 * Real MariaDB 11.x round-trip coverage. Requires OPENSTATSPEC_MARIADB_* variables.
 */
final class MariaDbSpssRoundTripTest extends MySqlFamilySpssRoundTripTestCase
{
    protected function serviceName(): string
    {
        return 'mariadb';
    }

    protected function environmentPrefix(): string
    {
        return 'OPENSTATSPEC_MARIADB';
    }
}
