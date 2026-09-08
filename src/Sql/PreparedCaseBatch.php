<?php

declare(strict_types=1);

namespace OpenStatSpec\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use PDO;
use PDOStatement;

/** @internal Shared bounded sender for already validated, encoded case rows. */
final class PreparedCaseBatch
{
    /** @param iterable<list<int|string|null>> $rows */
    public static function send(PDO $pdo, string $prefix, iterable $rows, ?int $payloadBudget = null): void
    {
        $byteLimit = min(1_048_576, $payloadBudget ?? 1_048_576);
        $values = [];
        $count = 0;
        $bytes = 0;
        $statement = null;
        $lastCount = 0;
        $tuple = '';

        foreach ($rows as $row) {
            $columns = count($row);
            if ($tuple === '') {
                $tuple = '(' . implode(', ', array_fill(0, $columns, '?')) . ')';
            }
            // 16 bytes/value cover length/type framing and placeholder/quote
            // syntax; NULL needs only eight, including its marker and separator.
            // MySQL's budget already reserves the prefix and worst-case escaping.
            $rowBytes = 4;
            foreach ($row as $value) {
                $rowBytes += $value === null ? 8 : 16 + strlen((string) $value);
            }
            if ($count > 0 && ($count >= min(256, intdiv(65_535, $columns)) || $bytes + $rowBytes > $byteLimit)) {
                self::flush($pdo, $prefix, $tuple, $values, $count, $statement, $lastCount);
                $values = [];
                $count = $bytes = 0;
            }
            // Grouping is not acceptance: preflight-valid oversized rows go alone.
            array_push($values, ...$row);
            ++$count;
            $bytes += $rowBytes;
        }
        self::flush($pdo, $prefix, $tuple, $values, $count, $statement, $lastCount);
    }

    /** @param list<int|string|null> $values */
    private static function flush(PDO $pdo, string $prefix, string $tuple, array $values, int $count, ?PDOStatement &$statement, int &$lastCount): void
    {
        if ($count === 0) {
            return;
        }
        if ($statement === null || $count !== $lastCount) {
            $prepared = $pdo->prepare($prefix . implode(', ', array_fill(0, $count, $tuple)));
            if ($prepared === false) {
                throw new UnsupportedOperation(DiagnosticCode::InvalidSourceDataset, 'Could not prepare a required data statement.');
            }
            $statement = $prepared;
            $lastCount = $count;
        }
        $statement->execute($values);
    }
}
