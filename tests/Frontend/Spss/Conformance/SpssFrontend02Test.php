<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Frontend\Spss\Conformance;

use OpenStatSpec\Frontend\Spss\Request\SpssFrontendRequest;
use OpenStatSpec\Frontend\Spss\SpssCompiler;
use OpenStatSpec\Tests\Support\SpecificationManifest;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Plan\PlanCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpssFrontend02Test extends TestCase
{
    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testOfficialFrontend02Case(array $case): void
    {
        /** @var array<string, mixed> $requestPayload */
        $requestPayload = $case['request'];
        $request = SpssFrontendRequest::fromArray($requestPayload);
        self::assertSame($case['expected_source_hash'], $request->sourceHash(), (string) $case['id']);

        try {
            $result = (new SpssCompiler())->compile($request);
        } catch (TransformationFailure $failure) {
            self::assertSame($case['expected_error'], $failure->diagnosticCode(), (string) $case['id']);
            self::assertNotNull($failure->diagnostics[0]->span, (string) $case['id']);

            return;
        }

        self::assertNull($case['expected_error'], (string) $case['id']);
        self::assertSame($case['expected_source_hash'], $result->sourceHash, (string) $case['id']);
        $codec = new PlanCodec();
        self::assertSame($case['expected_plan_hash'], $codec->hash($result->plan), (string) $case['id']);
        self::assertSame($this->expectedPlan($case), $result->plan->canonicalArray(), (string) $case['id']);
        self::assertSame(
            $result->plan->canonicalArray(),
            $codec->fromJson($codec->canonicalJson($result->plan))->canonicalArray(),
            (string) $case['id'],
        );

        foreach ($case['expected_output_metadata'] ?? [] as $name => $metadata) {
            $variables = array_column($request->inputSchema->variables, null, 'name');
            self::assertSame($metadata, [
                'variable_label' => $variables[$name]->variableLabel,
                'value_labels' => $variables[$name]->valueLabels,
            ]);
            // This inherited case promises RECODE leaves dictionary metadata alone.
            self::assertSame([], array_values(array_filter(
                $result->plan->canonicalArray()['operations'],
                static fn(array $operation): bool => ($operation['variable'] ?? null) === $name,
            )));
        }
        if (isset($case['expected_plan_contract'])) {
            self::assertSame($case['expected_plan_contract'], $result->plan->contract->value, (string) $case['id']);
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('official03Cases')]
    public function testOfficialFrontend03Case(array $case): void
    {
        $this->testOfficialFrontend02Case($case);
    }

    public function testOfficial03ManifestCoverage(): void
    {
        self::assertCount(35, SpecificationManifest::load('conformance/spss-syntax-frontend-0.3.json')['cases']);
        self::assertCount(90, iterator_to_array(self::official03Cases()));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function official03Cases(): iterable
    {
        $manifest = SpecificationManifest::load('conformance/spss-syntax-frontend-0.3.json');
        foreach ($manifest['inherited_manifests'] as $inheritance) {
            foreach (SpecificationManifest::load('conformance/' . $inheritance['manifest'])['cases'] as $case) {
                if (isset($inheritance['superseded_cases'][$case['id']])) {
                    continue;
                }
                if ($inheritance['manifest'] === 'spss-syntax-frontend-0.1.json' && isset($case['expected_plan_case'])) {
                    foreach (SpecificationManifest::load('conformance/transformation-plan-0.1.json')['cases'] as $planCase) {
                        if ($planCase['id'] === $case['expected_plan_case']) {
                            $case['expected_plan'] = $planCase['plan'];
                            $case['expected_plan_hash'] = $planCase['expected_plan_hash'];
                        }
                    }
                }
                $case['request']['contract'] = $inheritance['request_contract_override'];
                yield $inheritance['manifest'] . '/' . $case['id'] => [$case];
            }
        }
        foreach ($manifest['cases'] as $case) {
            yield '0.3/' . $case['id'] => [$case];
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function cases(): iterable
    {
        $manifest = SpecificationManifest::load('conformance/spss-syntax-frontend-0.2.json');
        /** @var list<array<string, mixed>> $cases */
        $cases = $manifest['cases'];
        foreach ($cases as $case) {
            yield (string) $case['id'] => [$case];
        }
    }

    /**
     * @param array<string, mixed> $case
     * @return array<string, mixed>
     */
    private function expectedPlan(array $case): array
    {
        if (isset($case['expected_plan']) && is_array($case['expected_plan'])) {
            return $case['expected_plan'];
        }
        if (isset($case['expected_plan_case'])) {
            return $this->namedPlan('conformance/transformation-plan-0.2.json', (string) $case['expected_plan_case'], 'plan');
        }
        if (isset($case['expected_plan_case_0_1'])) {
            return $this->namedPlan('conformance/spss-syntax-frontend-0.1.json', (string) $case['expected_plan_case_0_1'], 'expected_plan');
        }
        if (isset($case['expected_plan_0_1']) && is_array($case['expected_plan_0_1'])) {
            return $case['expected_plan_0_1'];
        }

        self::fail('Successful frontend case has no expected plan: ' . $case['id']);
    }

    /** @return array<string, mixed> */
    private function namedPlan(string $manifestPath, string $caseId, string $planKey): array
    {
        $manifest = SpecificationManifest::load($manifestPath);
        /** @var list<array<string, mixed>> $cases */
        $cases = $manifest['cases'];
        foreach ($cases as $case) {
            if ($case['id'] === $caseId && isset($case[$planKey]) && is_array($case[$planKey])) {
                return $case[$planKey];
            }
        }

        self::fail('Referenced plan case was not found: ' . $caseId);
    }
}
