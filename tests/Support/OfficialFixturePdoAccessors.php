<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Support;

use PDO;

/**
 * Shared thin wrappers over the official-conformance integration suite's
 * PDO accessors so every helper appears in exactly one place. Both
 * OfficialInPlaceTransformation01Test and OfficialInPlaceTransformation02Test
 * pull these in via `use` traits; new official conformance suites should
 * do the same instead of redefining the accessors.
 */
trait OfficialFixturePdoAccessors
{
    /** @param list<mixed> $parameters */
    private function scalar(PDO $pdo, string $sql, array $parameters = []): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchColumn();
    }

    /**
     * @param list<mixed> $parameters
     * @return list<mixed>
     */
    private function column(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(PDO $pdo, string $sql, array $parameters = []): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
