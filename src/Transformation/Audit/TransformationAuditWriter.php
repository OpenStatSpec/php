<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Audit;

use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Execution\DatasetBinding;
use OpenStatSpec\Transformation\Execution\DoltEvidence;
use OpenStatSpec\Transformation\Execution\InPlaceApplyRequest;
use OpenStatSpec\Transformation\Plan\PlanCodec;
use OpenStatSpec\Transformation\Plan\PlanContract;
use PDO;
use PDOException;

/** Writes only the one compact success record inside the caller's apply transaction. */
final readonly class TransformationAuditWriter
{
    private PlanCodec $codec;

    public function __construct(private PDO $pdo, ?PlanCodec $codec = null)
    {
        $this->codec = $codec ?? new PlanCodec();
    }

    public function succeed(
        InPlaceApplyRequest $request,
        DatasetBinding $dataset,
        string $databaseProfile,
        ?DoltEvidence $before,
        ?DoltEvidence $after,
    ): string {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('A successful transformation audit must be written inside the open apply transaction.');
        }
        if (!in_array($databaseProfile, ['sqlite', 'postgresql', 'mysql', 'mariadb', 'dolt'], true)) {
            throw new \InvalidArgumentException('Unsupported transformation audit database profile.');
        }
        if ($dataset->datasetId !== $request->datasetId) {
            throw TransformationFailure::at('invalid_dataset_id', '$.dataset_id', 'Apply request and target binding identify different datasets.');
        }

        $this->assertDatasetIdentity($dataset);
        [$branch, $headBefore, $headAfter] = $this->doltFields($request, $databaseProfile, $before, $after);
        $applyId = self::uuidV4();
        $timestamp = gmdate('Y-m-d H:i:s');
        $contract = $request->plan->contract === PlanContract::V01
            ? 'openstatspec-in-place-transformation-v0.1'
            : 'openstatspec-in-place-transformation-v0.2';

        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO transformation_apply (
    apply_id, contract_id, database_profile, dataset_id, physical_table_schema,
    physical_table_name, source_hash, plan_hash, canonical_plan_json, actor,
    status, dolt_branch, dolt_head_before, dolt_head_after, operation_count,
    started_at, completed_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
        if ($statement === false) {
            throw new PDOException('The transformation audit insert could not be prepared.');
        }
        $statement->execute([
            $applyId,
            $contract,
            $databaseProfile,
            $dataset->datasetId,
            $dataset->schema,
            $dataset->table,
            $request->sourceHash,
            $this->codec->hash($request->plan),
            $this->codec->canonicalJson($request->plan),
            $request->actor,
            'succeeded',
            $branch,
            $headBefore,
            $headAfter,
            count($request->plan->operations),
            $timestamp,
            $timestamp,
        ]);

        return $applyId;
    }

    private function assertDatasetIdentity(DatasetBinding $dataset): void
    {
        $statement = $this->pdo->prepare(
            'SELECT physical_table_schema, physical_table_name FROM dataset WHERE dataset_id = ?',
        );
        if ($statement === false) {
            throw new PDOException('The audit target dataset identity could not be prepared.');
        }
        $statement->execute([$dataset->datasetId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1
            || ($rows[0]['physical_table_schema'] ?? null) !== $dataset->schema
            || ($rows[0]['physical_table_name'] ?? null) !== $dataset->table
        ) {
            throw TransformationFailure::at(
                'invalid_dataset_id',
                '$.dataset_id',
                'Apply target identity no longer matches the normative dataset row.',
            );
        }
    }

    /** @return array{string|null, string|null, string|null} */
    private function doltFields(
        InPlaceApplyRequest $request,
        string $databaseProfile,
        ?DoltEvidence $before,
        ?DoltEvidence $after,
    ): array {
        if ($databaseProfile !== 'dolt') {
            if ($before !== null || $after !== null) {
                throw new \InvalidArgumentException('Non-Dolt audit rows cannot contain Dolt evidence.');
            }
            return [null, null, null];
        }
        if ($request->expectedBranch === null
            || $request->expectedHead === null
            || $before === null
            || $after === null
        ) {
            throw TransformationFailure::at('dolt_context_required', '$.expected_context', 'Dolt apply audit requires complete context evidence.');
        }
        if ($before->branch() !== $request->expectedBranch
            || $before->head() !== $request->expectedHead
            || $after->branch() !== $before->branch()
            || $after->head() !== $before->head()
        ) {
            throw TransformationFailure::at('dolt_context_changed', '$.expected_context', 'Dolt context changed before the success audit.');
        }
        return [$before->branch(), $before->head(), $after->head()];
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
