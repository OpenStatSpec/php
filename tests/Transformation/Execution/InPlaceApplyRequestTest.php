<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Execution;

use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Execution\InPlaceApplyRequest;
use OpenStatSpec\Transformation\Plan\Operation\ExecuteOperation;
use OpenStatSpec\Transformation\Plan\PlanContract;
use OpenStatSpec\Transformation\Plan\TransformationPlan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InPlaceApplyRequestTest extends TestCase
{
    public function testCanonicalRfc4122V4DatasetIdIsAccepted(): void
    {
        $request = $this->build('018f47f2-8b6a-7c3d-8e1f-123456789abc');
        self::assertSame('018f47f2-8b6a-7c3d-8e1f-123456789abc', $request->datasetId);
    }

    public function testMicrosoftVariantDatasetIdIsAccepted(): void
    {
        // RFC 4122 reserves the 10xx variant bits (8/9/a/b) and the 110x bits (c/d) for
        // Microsoft backward compatibility; deployment tooling that emits Microsoft-style
        // GUIDs must not be rejected as invalid.
        $request = $this->build('018f47f2-8b6a-7c3d-8e1f-123456789cda');
        self::assertSame('018f47f2-8b6a-7c3d-8e1f-123456789cda', $request->datasetId);
    }

    public function testNcsVariantDatasetIdIsRejected(): void
    {
        // NCS (variant bits 0xxx) is the legacy Apollo NCS UUID; OpenStatSpec does not
        // accept it because every catalog row is RFC 4122 or Microsoft variant.
        $this->expectException(TransformationFailure::class);
        $this->expectExceptionMessage('Apply dataset identity must be a canonical lowercase UUID');
        $this->build('018f47f2-8b6a-7c3d-0e1f-123456789abc');
    }

    public function testReservedVariantDatasetIdIsRejected(): void
    {
        // 1110xxxx (e/f) is reserved for future use; OpenStatSpec rejects it to keep
        // variant semantics tight.
        $this->expectException(TransformationFailure::class);
        $this->build('018f47f2-8b6a-7c3d-ee1f-123456789abc');
    }

    /** @return iterable<string, array{string}> */
    public static function malformedDatasetIds(): iterable
    {
        yield 'empty string' => [''];
        yield 'wrong length' => ['018f47f2-8b6a-7c3d-8e1f-123456789'];
        yield 'wrong separator' => ['018f47f28b6a7c3d8e1f123456789abc'];
        yield 'uppercase hex' => ['018F47F2-8B6A-7C3D-8E1F-123456789ABC'];
        yield 'non-hex character' => ['018f47f2-8b6a-7c3d-8e1f-123456789abz'];
    }

    #[DataProvider('malformedDatasetIds')]
    public function testMalformedDatasetIdIsRejected(string $datasetId): void
    {
        $this->expectException(TransformationFailure::class);
        $this->build($datasetId);
    }

    public function testEmptyActorIsRejected(): void
    {
        $this->expectException(TransformationFailure::class);
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [new ExecuteOperation()]);
        new InPlaceApplyRequest($plan, 'parent', '018f47f2-8b6a-7c3d-8e1f-123456789abc', str_repeat('a', 64), '');
    }

    public function testDoltBranchAndHeadMustBeSuppliedTogether(): void
    {
        $this->expectException(TransformationFailure::class);
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [new ExecuteOperation()]);
        new InPlaceApplyRequest($plan, 'parent', '018f47f2-8b6a-7c3d-8e1f-123456789abc', str_repeat('a', 64), 'actor', 'main', null);
    }

    public function testInvalidSourceHashIsRejected(): void
    {
        $this->expectException(TransformationFailure::class);
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [new ExecuteOperation()]);
        new InPlaceApplyRequest($plan, 'parent', '018f47f2-8b6a-7c3d-8e1f-123456789abc', 'not-a-hash', 'actor');
    }

    public function testAliasMismatchIsRejected(): void
    {
        $this->expectException(TransformationFailure::class);
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [new ExecuteOperation()]);
        new InPlaceApplyRequest($plan, 'other', '018f47f2-8b6a-7c3d-8e1f-123456789abc', str_repeat('a', 64), 'actor');
    }

    private function build(string $datasetId): InPlaceApplyRequest
    {
        $plan = new TransformationPlan(PlanContract::V02, 'parent', [new ExecuteOperation()]);
        return new InPlaceApplyRequest($plan, 'parent', $datasetId, str_repeat('a', 64), 'conformance-runner');
    }
}
