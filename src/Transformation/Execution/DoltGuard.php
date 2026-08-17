<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;

/** Fail-closed Dolt guard; it observes repository state but never mutates it. */
final readonly class DoltGuard
{
    public function __construct(private DoltEvidenceReader $reader) {}

    public function beforeExecution(InPlaceApplyRequest $request): DoltEvidence
    {
        if ($request->expectedBranch === null || $request->expectedHead === null) {
            throw TransformationFailure::at(
                'dolt_context_required',
                '$.expected_context',
                'Dolt applies require the expected branch and HEAD.',
            );
        }

        $evidence = $this->reader->read();
        if ($evidence->branch() !== $request->expectedBranch) {
            throw TransformationFailure::at(
                'dolt_branch_mismatch',
                '$.expected_context.branch',
                'The active Dolt branch does not match the expected branch.',
            );
        }
        if ($evidence->head() !== $request->expectedHead) {
            throw TransformationFailure::at(
                'dolt_head_mismatch',
                '$.expected_context.head',
                'Dolt HEAD does not match the expected HEAD.',
            );
        }
        if (!$evidence->isClean()) {
            throw TransformationFailure::at(
                'dolt_working_set_dirty',
                '$.expected_context.working_set_clean',
                'Dolt transformations require a clean working set before execution; dirty tables: '
                    . implode(', ', $evidence->dirtyTables()) . '.',
            );
        }

        return $evidence;
    }

    public function afterExecution(InPlaceApplyRequest $request, DoltEvidence $before): DoltEvidence
    {
        $after = $this->reader->read();
        if ($request->expectedBranch === null
            || $request->expectedHead === null
            || $before->branch() !== $request->expectedBranch
            || $before->head() !== $request->expectedHead
            || $after->branch() !== $request->expectedBranch
            || $after->head() !== $request->expectedHead
            || $after->branch() !== $before->branch()
            || $after->head() !== $before->head()
        ) {
            throw TransformationFailure::at(
                'dolt_context_changed',
                '$.expected_context',
                'The Dolt branch or HEAD changed during transformation execution.',
            );
        }

        return $after;
    }
}
