<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Support;

use PDO;
use PDOStatement;

/** Real driver executions, enabled only around export (never fixture setup). */
final class ExportCountingPdo extends PDO
{
    /** @var null|list<array{sql: string, parameters: null|array<array-key, mixed>}> */
    public ?array $executions = null;
    public int $prepares = 0;

    /** @param array<int, mixed> $options */
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = [])
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ExportCountingStatement::class, [$this]]);
    }

    /** @return list<array{sql: string, parameters: null|array<array-key, mixed>}> */
    public function captured(): array
    {
        return $this->executions ?? [];
    }

    /** @param null|array<array-key, mixed> $parameters */
    public function record(string $sql, ?array $parameters = null): void
    {
        if ($this->executions !== null) {
            $this->executions[] = ['sql' => $sql, 'parameters' => $parameters];
        }
    }

    /** @param array<int, mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->executions !== null) {
            ++$this->prepares;
        }
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->record($query);
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->record($statement);
        return parent::exec($statement);
    }
}

final class ExportCountingStatement extends PDOStatement
{
    protected function __construct(private ExportCountingPdo $pdo) {}

    /** @param null|array<array-key, mixed> $params */
    public function execute(?array $params = null): bool
    {
        $this->pdo->record($this->queryString, $params);
        return parent::execute($params);
    }
}
