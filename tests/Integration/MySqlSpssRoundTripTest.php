<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Integration;

/**
 * Real MySQL 8.4 round-trip coverage. Requires OPENSTATSPEC_MYSQL_* variables.
 */
final class MySqlSpssRoundTripTest extends MySqlFamilySpssRoundTripTestCase
{
    protected function serviceName(): string
    {
        return 'mysql';
    }

    protected function environmentPrefix(): string
    {
        return 'OPENSTATSPEC_MYSQL';
    }
}
