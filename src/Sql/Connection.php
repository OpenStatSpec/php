<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use PDO;

final readonly class Connection
{
    public function __construct(public PDO $pdo) {}
}
