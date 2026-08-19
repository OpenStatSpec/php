<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Plan\TransformationPlan;

final readonly class InPlaceApplyRequest
{
    public function __construct(
        public TransformationPlan $plan,
        public string $inputAlias,
        public string $datasetId,
        public string $sourceHash,
        public string $actor,
        public ?string $expectedBranch = null,
        public ?string $expectedHead = null,
    ) {
        if ($inputAlias !== $plan->inputAlias) {
            throw TransformationFailure::at('unknown_input_alias', '$.input_alias', 'Apply binding does not match the plan alias.');
        }
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89abcd][0-9a-f]{3}-[0-9a-f]{12}\z/D', $datasetId) !== 1) {
            throw TransformationFailure::at('invalid_dataset_id', '$.dataset_id', 'Apply dataset identity must be a canonical lowercase UUID with an RFC 4122 (8/9/a/b) or Microsoft (c/d) variant.');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/D', $sourceHash) !== 1) {
            throw TransformationFailure::at('invalid_source_hash', '$.source_hash', 'Apply source hash must be a lowercase SHA-256 value.');
        }
        if (trim($actor) === '') {
            throw TransformationFailure::at('actor_required', '$.actor', 'A non-empty actor is required.');
        }

        $branchPresent = $expectedBranch !== null && trim($expectedBranch) !== '';
        $headPresent = $expectedHead !== null && trim($expectedHead) !== '';
        if ($branchPresent !== $headPresent
            || ($expectedBranch !== null && !$branchPresent)
            || ($expectedHead !== null && !$headPresent)
        ) {
            throw TransformationFailure::at(
                'dolt_context_required',
                '$.expected_context',
                'Expected Dolt branch and HEAD must be supplied together as non-empty values.',
            );
        }
    }
}
