<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Model;

use OpenStatSpec\Transformation\Canonical\CanonicalJson;

/**
 * Canonical source-language-neutral plan for mutating one registered dataset.
 *
 * The fixed in_place mode deliberately has no output dataset, table, branch,
 * snapshot, or rollback identity. SQL transaction and Dolt versioning concerns
 * belong to the executor, not to this plan.
 */
final readonly class TransformationPlan
{
    public const CONTRACT = 'openstatspec-transformation-plan-v1';

    /** @param list<TransformationOperation> $operations */
    public function __construct(
        private string $datasetId,
        private array $operations,
    ) {}

    public function datasetId(): string
    {
        return $this->datasetId;
    }

    /** @return list<TransformationOperation> */
    public function operations(): array
    {
        return $this->operations;
    }

    /** @return array{contract: string, mode: string, dataset_id: string, operations: list<array<string, mixed>>} */
    public function canonicalArray(): array
    {
        return [
            'contract' => self::CONTRACT,
            'mode' => 'in_place',
            'dataset_id' => $this->datasetId,
            'operations' => array_map(
                static fn(TransformationOperation $operation): array => $operation->canonicalArray(),
                $this->operations,
            ),
        ];
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->canonicalArray());
    }

    public function hash(): string
    {
        return hash('sha256', $this->canonicalJson());
    }
}
