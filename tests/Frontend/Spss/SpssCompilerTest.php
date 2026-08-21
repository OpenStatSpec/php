<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Frontend\Spss;

use OpenStatSpec\Frontend\Spss\Request\SpssFrontendRequest;
use OpenStatSpec\Frontend\Spss\Request\InputVariable;
use OpenStatSpec\Frontend\Spss\SpssCompiler;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use OpenStatSpec\Transformation\Plan\PlanContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpssCompilerTest extends TestCase
{
    public function testRequestValidationPreservesOrderedSchemaAndNormalizesOnlySourceLineEndingsForHash(): void
    {
        $request = SpssFrontendRequest::fromArray([
            'source_text' => "COMPUTE target = first.\r\nEXECUTE.\r",
            'input_schema' => [
                'variables' => [
                    [
                        'name' => 'first',
                        'storage_kind' => 'numeric',
                        'variable_label' => 'First',
                        'value_labels' => [[
                            'value' => ['type' => 'binary64', 'bits' => '3ff0000000000000'],
                            'label' => 'One',
                        ]],
                        'format_family' => 'F',
                        'width' => 8,
                        'decimals' => 2,
                        'measurement_level' => 'scale',
                    ],
                    ['name' => 'second', 'storage_kind' => 'string'],
                ],
            ],
            'input_alias' => 'parent',
            'contract' => 'openstatspec-spss-syntax-frontend-v0.2',
        ]);

        self::assertSame(['first', 'second'], array_column($request->inputSchema->variables, 'name'));
        self::assertSame("COMPUTE target = first.\r\nEXECUTE.\r", $request->sourceText);
        self::assertSame(
            hash('sha256', "COMPUTE target = first.\nEXECUTE.\n"),
            $request->sourceHash(),
        );
    }

    public function testDirectInputVariableConstructionValidatesValueLabelShape(): void
    {
        $this->expectException(TransformationFailure::class);

        new InputVariable(
            'q1',
            'numeric',
            valueLabels: [['value' => ['type' => 'binary64', 'bits' => 'bad'], 'label' => 'Bad']],
        );
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidRequestProvider')]
    public function testRequestRejectsEveryNonSchemaShape(array $payload, string $path): void
    {
        try {
            SpssFrontendRequest::fromArray($payload);
            self::fail('An invalid frontend request unexpectedly passed validation.');
        } catch (TransformationFailure $failure) {
            self::assertSame('plan_schema_invalid', $failure->diagnosticCode());
            self::assertSame($path, $failure->diagnostics[0]->path);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidRequestProvider(): iterable
    {
        $valid = self::payload('EXECUTE.', [['name' => 'q1', 'storage_kind' => 'numeric']]);

        yield 'extra root member' => [$valid + ['extra' => true], '$'];
        yield 'wrong contract' => [[...$valid, 'contract' => 'openstatspec-spss-syntax-frontend-v0.1'], '$.contract'];
        yield 'empty alias' => [[...$valid, 'input_alias' => ''], '$.input_alias'];
        yield 'empty source' => [[...$valid, 'source_text' => ''], '$.source_text'];
        yield 'empty variables' => [[...$valid, 'input_schema' => ['variables' => []]], '$.input_schema.variables'];
        yield 'extra schema member' => [[...$valid, 'input_schema' => ['variables' => [['name' => 'q1', 'storage_kind' => 'numeric']], 'extra' => null]], '$.input_schema'];
        yield 'empty variable name' => [self::payload('EXECUTE.', [['name' => '', 'storage_kind' => 'numeric']]), '$.input_schema.variables[0].name'];
        yield 'invalid storage kind' => [self::payload('EXECUTE.', [['name' => 'q1', 'storage_kind' => 'date']]), '$.input_schema.variables[0].storage_kind'];
        yield 'extra variable member' => [self::payload('EXECUTE.', [['name' => 'q1', 'storage_kind' => 'numeric', 'extra' => true]]), '$.input_schema.variables[0]'];
        yield 'invalid value label bits' => [self::payload('EXECUTE.', [[
            'name' => 'q1',
            'storage_kind' => 'numeric',
            'value_labels' => [['value' => ['type' => 'binary64', 'bits' => '8000000000000000'], 'label' => 'bad']],
        ]]), '$.input_schema.variables[0].value_labels[0].value.bits'];
        yield 'zero format width' => [self::payload('EXECUTE.', [['name' => 'q1', 'storage_kind' => 'numeric', 'width' => 0]]), '$.input_schema.variables[0].width'];
        yield 'invalid level' => [self::payload('EXECUTE.', [['name' => 'q1', 'storage_kind' => 'numeric', 'measurement_level' => 'interval']]), '$.input_schema.variables[0].measurement_level'];
    }

    public function testBindingUsesExactCatalogSpellingAndEvolvingCaseInsensitiveSchema(): void
    {
        $result = (new SpssCompiler())->compile(SpssFrontendRequest::fromArray(self::payload(
            "COMPUTE Temp = source.\nIF (TEMP = 1) temp = SOURCE.\nFORMATS tEmP (F8.2).",
            [['name' => 'Source', 'storage_kind' => 'numeric']],
        )));

        self::assertSame(PlanContract::V02, $result->plan->contract);
        self::assertSame([
            ['op' => 'assign', 'target' => 'Temp', 'target_mode' => 'create', 'value' => ['kind' => 'variable', 'variable' => 'Source']],
            [
                'op' => 'conditional_assign',
                'condition' => [
                    'expression' => 'comparison',
                    'left' => ['kind' => 'variable', 'variable' => 'Temp'],
                    'operator' => '=',
                    'right' => ['kind' => 'literal', 'value' => ['type' => 'binary64', 'bits' => '3ff0000000000000']],
                ],
                'target' => 'Temp',
                'value' => ['kind' => 'variable', 'variable' => 'Source'],
            ],
            ['op' => 'set_format', 'variable' => 'Temp', 'family' => 'F', 'width' => 8, 'decimals' => 2],
        ], $result->plan->canonicalArray()['operations']);
    }

    public function testParallelRecodeReadsPrecommandSchemaAndPublishesTargetsAfterTheCommand(): void
    {
        $result = (new SpssCompiler())->compile(SpssFrontendRequest::fromArray(self::payload(
            "RECODE FIRST second (1 = 9) INTO First_New Second_New.\nVARIABLE LABELS first_new 'Created'.",
            [
                ['name' => 'First', 'storage_kind' => 'numeric'],
                ['name' => 'Second', 'storage_kind' => 'numeric'],
            ],
        )));

        self::assertSame(PlanContract::V01, $result->plan->contract);
        self::assertSame(
            [['First', 'First_New'], ['Second', 'Second_New']],
            array_map(
                static fn(array $operation): array => [$operation['source'], $operation['target']],
                array_slice($result->plan->canonicalArray()['operations'], 0, 2),
            ),
        );
        self::assertSame('First_New', $result->plan->canonicalArray()['operations'][2]['variable']);
    }

    public function testRecodeAndValueLabelsUseExactDecimalBinary64Bits(): void
    {
        $token = '1.00000000000000033306690738754696212708950042724609375';
        $result = (new SpssCompiler())->compile(SpssFrontendRequest::fromArray(self::payload(
            "RECODE q1 ($token = -0).\nVALUE LABELS q1 $token 'Rounded'.",
            [['name' => 'q1', 'storage_kind' => 'numeric']],
        )));
        $operations = $result->plan->canonicalArray()['operations'];

        self::assertSame('3ff0000000000002', $operations[0]['rules'][0]['match']['values'][0]['bits']);
        self::assertSame('0000000000000000', $operations[0]['rules'][0]['result']['value']['bits']);
        self::assertSame('3ff0000000000002', $operations[1]['labels'][0]['value']['bits']);
    }

    public function testStringSourceCanCreateNumericTargetWithExplicitSystemMissingElse(): void
    {
        $result = (new SpssCompiler())->compile(SpssFrontendRequest::fromArray(self::payload(
            "RECODE color ('R' = 1) (ELSE = SYSMIS) INTO code.",
            [['name' => 'color', 'storage_kind' => 'string']],
        )));

        self::assertSame('system_missing', $result->plan->canonicalArray()['operations'][0]['unmatched']['kind']);
    }

    public function testRecodeWithOnlyElseCannotEmitAnInvalidEmptyRuleList(): void
    {
        try {
            (new SpssCompiler())->compile(SpssFrontendRequest::fromArray(self::payload(
                'RECODE q1 (ELSE = 0).',
                [['name' => 'q1', 'storage_kind' => 'numeric']],
            )));
            self::fail('An ELSE-only RECODE unexpectedly emitted an invalid plan.');
        } catch (TransformationFailure $failure) {
            self::assertSame('spss_syntax_error', $failure->diagnosticCode());
            self::assertSame([0, 20], [
                $failure->diagnostics[0]->span?->startOffset,
                $failure->diagnostics[0]->span?->endOffset,
            ]);
        }
    }

    /** @param list<array<string, mixed>> $variables */
    #[DataProvider('bindingFailureProvider')]
    public function testBindingFailuresHaveStableCodesAndExactSourceSpans(
        string $source,
        array $variables,
        string $code,
        int $startOffset,
        int $endOffset,
    ): void {
        try {
            (new SpssCompiler())->compile(SpssFrontendRequest::fromArray(self::payload($source, $variables)));
            self::fail('Invalid frontend semantics unexpectedly compiled.');
        } catch (TransformationFailure $failure) {
            self::assertSame($code, $failure->diagnosticCode());
            $span = $failure->diagnostics[0]->span;
            self::assertNotNull($span);
            self::assertSame([$startOffset, $endOffset], [$span->startOffset, $span->endOffset]);
            self::assertSame([1, $startOffset + 1, 1, $endOffset + 1], [
                $span->startLine,
                $span->startColumn,
                $span->endLine,
                $span->endColumn,
            ]);
        }
    }

    /** @return iterable<string, array{string, list<array<string, mixed>>, string, int, int}> */
    public static function bindingFailureProvider(): iterable
    {
        yield 'unknown expression variable' => [
            'COMPUTE target = missing.',
            [['name' => 'q1', 'storage_kind' => 'numeric']],
            'unknown_variable',
            17,
            24,
        ];
        yield 'missing conditional target' => [
            'IF (source = 1) missing = 1.',
            [['name' => 'source', 'storage_kind' => 'numeric']],
            'conditional_target_missing',
            16,
            23,
        ];
        yield 'reserved new compute target' => [
            'COMPUTE __hidden = 1.',
            [['name' => 'source', 'storage_kind' => 'numeric']],
            'reserved_target_name',
            8,
            16,
        ];
        yield 'reserved existing compute target' => [
            'COMPUTE __hidden = 1.',
            [['name' => '__Hidden', 'storage_kind' => 'numeric']],
            'reserved_target_name',
            8,
            16,
        ];
        yield 'reserved existing conditional target' => [
            'IF (source = 1) __hidden = 1.',
            [
                ['name' => 'source', 'storage_kind' => 'numeric'],
                ['name' => '__Hidden', 'storage_kind' => 'numeric'],
            ],
            'reserved_target_name',
            16,
            24,
        ];
        yield 'reserved existing recode target' => [
            'RECODE __Hidden (1 = 2).',
            [['name' => '__Hidden', 'storage_kind' => 'numeric']],
            'reserved_target_name',
            7,
            15,
        ];
        yield 'string compute expression' => [
            'COMPUTE target = color.',
            [['name' => 'color', 'storage_kind' => 'string']],
            'expression_type_unsupported',
            17,
            22,
        ];
        yield 'string compute target' => [
            'COMPUTE color = 1.',
            [['name' => 'color', 'storage_kind' => 'string']],
            'expression_type_unsupported',
            8,
            13,
        ];
        yield 'value label type mismatch' => [
            "VALUE LABELS q1 'R' 'Red'.",
            [['name' => 'q1', 'storage_kind' => 'numeric']],
            'type_mismatch',
            13,
            25,
        ];
        yield 'invalid format bounds' => [
            'FORMATS q1 (F2.2).',
            [['name' => 'q1', 'storage_kind' => 'numeric']],
            'invalid_format',
            8,
            17,
        ];
        yield 'duplicate value label' => [
            "VALUE LABELS q1 -0 'Minus' 0 'Plus'.",
            [['name' => 'q1', 'storage_kind' => 'numeric']],
            'duplicate_value_label',
            13,
            35,
        ];
        yield 'system missing string recode' => [
            "RECODE color (SYSMIS = 'x').",
            [['name' => 'color', 'storage_kind' => 'string']],
            'system_missing_for_string',
            14,
            20,
        ];
        yield 'string target needs declaration' => [
            "RECODE color ('R' = 'red') INTO normalized.",
            [['name' => 'color', 'storage_kind' => 'string']],
            'string_target_requires_declaration',
            32,
            42,
        ];
        yield 'mixed recode results' => [
            "RECODE q1 (1 = 'one') (ELSE = 0).",
            [['name' => 'q1', 'storage_kind' => 'numeric']],
            'mixed_result_types',
            0,
            32,
        ];
    }

    /**
     * @param list<array<string, mixed>> $variables
     * @return array<string, mixed>
     */
    private static function payload(string $source, array $variables): array
    {
        return [
            'contract' => 'openstatspec-spss-syntax-frontend-v0.2',
            'input_alias' => 'parent',
            'input_schema' => ['variables' => $variables],
            'source_text' => $source,
        ];
    }
}
