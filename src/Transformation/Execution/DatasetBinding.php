<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

use OpenStatSpec\Sql\PdoSqlProfile;

/** @internal Exact normative-catalog binding for one existing dataset. */
final readonly class DatasetBinding
{
    public function __construct(
        public string $datasetId,
        public ?string $datasetName,
        public ?string $schema,
        public string $table,
    ) {}

    public function qualifiedTable(PdoSqlProfile $profile): string
    {
        $table = $profile->quoteIdentifier($this->table);

        return $this->schema === null ? $table : $profile->quoteIdentifier($this->schema) . '.' . $table;
    }
}
