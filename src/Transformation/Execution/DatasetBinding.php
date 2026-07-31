<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

/** @internal Exact normative-catalog binding for one existing dataset. */
final readonly class DatasetBinding
{
    public function __construct(
        public string $datasetId,
        public ?string $datasetName,
        public ?string $schema,
        public string $table,
    ) {}
}
