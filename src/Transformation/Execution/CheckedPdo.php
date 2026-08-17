<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/** @internal Converts every false PDO outcome in the apply boundary into failure. */
final class CheckedPdo
{
    public static function prepare(PDO $pdo, string $sql, string $message): PDOStatement
    {
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            throw self::failure($message, $pdo->errorInfo());
        }

        return $statement;
    }

    /** @param list<mixed> $parameters */
    public static function execute(PDOStatement $statement, array $parameters, string $message): void
    {
        if (!$statement->execute($parameters)) {
            throw self::failure($message, $statement->errorInfo());
        }
    }

    public static function exec(PDO $pdo, string $sql, string $message): int
    {
        $affected = $pdo->exec($sql);
        if ($affected === false) {
            throw self::failure($message, $pdo->errorInfo());
        }

        return $affected;
    }

    public static function begin(PDO $pdo): void
    {
        if (!$pdo->beginTransaction()) {
            throw self::failure('The SQL driver did not start the transformation transaction.', $pdo->errorInfo());
        }
    }

    public static function commit(PDO $pdo): void
    {
        if (!$pdo->commit()) {
            throw self::failure('The SQL driver did not commit the transformation transaction.', $pdo->errorInfo());
        }
    }

    public static function rollback(PDO $pdo, Throwable $applyFailure): void
    {
        try {
            if (!$pdo->rollBack()) {
                throw self::failure('The SQL driver did not roll back the failed transformation transaction.', $pdo->errorInfo());
            }
        } catch (Throwable $rollbackFailure) {
            throw new PDOException(
                'The failed transformation transaction rollback failed: ' . $rollbackFailure->getMessage(),
                0,
                $applyFailure,
            );
        }
    }

    /** @param array<int, mixed> $errorInfo */
    private static function failure(string $message, array $errorInfo): PDOException
    {
        $driverMessage = $errorInfo[2] ?? null;
        if (is_string($driverMessage) && $driverMessage !== '') {
            $message .= ' ' . $driverMessage;
        }

        return new PDOException($message);
    }
}
