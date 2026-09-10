<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Frontend\Spss;

use OpenStatSpec\Frontend\Spss\Request\SpssFrontendRequest;
use OpenStatSpec\Frontend\Spss\SpssCompiler;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpssFrontend03Test extends TestCase
{
    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidRequests')]
    public function testProductionRequestBoundaryRejectsInvalidShapes(array $payload): void
    {
        try {
            (new SpssCompiler())->compile(SpssFrontendRequest::fromArray($payload));
            self::fail('Invalid request compiled.');
        } catch (TransformationFailure $failure) {
            self::assertSame('plan_schema_invalid', $failure->diagnosticCode());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRequests(): iterable
    {
        foreach (SpssCompilerTest::invalidRequestProvider() as $name => [$payload]) {
            if ($name !== 'wrong contract') {
                $payload['contract'] = SpssFrontendRequest::CONTRACT_V03;
            }
            yield $name => [$payload];
        }
        $valid = self::payload('EXECUTE.');
        foreach (['contract' => 3, 'source_text' => [], 'input_alias' => false, 'input_schema' => 'schema'] as $key => $value) {
            yield 'wrong type ' . $key => [[...$valid, $key => $value]];
        }
        foreach (['value_labels' => null, 'width' => '8', 'decimals' => false, 'variable_label' => 1, 'format_family' => [], 'measurement_level' => true] as $key => $value) {
            $payload = $valid;
            $payload['input_schema']['variables'][0][$key] = $value;
            yield 'wrong variable type ' . $key => [$payload];
        }
    }

    #[DataProvider('lowerings')]
    public function testOfficialLoweringMatchesExplicitInheritedPlan(string $source, string $expanded): void
    {
        $compiler = new SpssCompiler();
        $official = $compiler->compile(SpssFrontendRequest::fromArray(self::payload($source)));
        $legacy = $compiler->compile(SpssFrontendRequest::fromArray([
            ...self::payload($expanded), 'contract' => SpssFrontendRequest::CONTRACT,
        ]));
        self::assertSame($legacy->plan->canonicalArray(), $official->plan->canonicalArray());
    }

    /** @return iterable<string, array{string, string}> */
    public static function lowerings(): iterable
    {
        foreach (['=' => 'a < 1 OR a > 1', '<' => 'a >= 1', '<=' => 'a > 1', '>' => 'a <= 1', '>=' => 'a < 1', 'NE' => 'a >= 1 AND a <= 1', '<>' => 'a >= 1 AND a <= 1', '~=' => 'a >= 1 AND a <= 1'] as $operator => $predicate) {
            yield 'NOT ' . $operator => ["IF (NOT a $operator 1) target = 1.", "IF ($predicate) target = 1."];
        }
        yield 'comparison then NOT then AND then OR' => [
            'IF (NOT a = 1 AND b = 2 OR c = 3) target = 1.',
            'IF (((a < 1 OR a > 1) AND b = 2) OR c = 3) target = 1.',
        ];
        yield 'parentheses override NOT scope' => [
            'IF (NOT (a = 1 AND b = 2 OR c = 3)) target = 1.',
            'IF ((a < 1 OR a > 1 OR b < 2 OR b > 2) AND (c < 3 OR c > 3)) target = 1.',
        ];
        yield 'aliases flatten with parenthesized siblings' => [
            'IF (a NE 1 OR (b <> 2 OR c ~= 3)) target = 1.',
            'IF (a < 1 OR a > 1 OR b < 2 OR b > 2 OR c < 3 OR c > 3) target = 1.',
        ];
        yield 'double NOT remains comparison' => ['IF (NOT NOT a = 1) target = 1.', 'IF (a = 1) target = 1.'];
        yield 'grouped recodes and labels use ordered evolving schema' => [
            "RECODE a TO c (1 = 0) INTO x y z / x TO z (0 = 2).\nVARIABLE LABELS a TO c 'Group' / target 'Target'.\nFORMATS a b (F8.2) / c target (F9.3).\nVARIABLE LEVEL a TO c (ORDINAL) / target (SCALE).",
            "RECODE a b c (1 = 0) INTO x y z. RECODE x y z (0 = 2).\nVARIABLE LABELS a 'Group' b 'Group' c 'Group' target 'Target'.\nFORMATS a (F8.2) b (F8.2) c (F9.3) target (F9.3).\nVARIABLE LEVEL a b c (ORDINAL) / target (SCALE).",
        ];
        yield 'chained dictionary ranges retain order without duplicate joints' => [
            "VALUE LABELS a TO b TO c 1 'One'.", "VALUE LABELS a b c 1 'One'.",
        ];
        yield 'ADD typed zero updates ordinal and groups see replacement' => [
            "VALUE LABELS a 5 'Five' 0 'Old'. ADD VALUE LABELS a -0 'Zero' 2 'Two' / a 5 'Cinq'.",
            "VALUE LABELS a 5 'Five' 0 'Old'. VALUE LABELS a 5 'Five' 0 'Zero' 2 'Two'. VALUE LABELS a 5 'Cinq' 0 'Zero' 2 'Two'.",
        ];
    }

    public function testAddStringLabelsUsesExactContentsAndKeepsExistingOrdinals(): void
    {
        $payload = self::payload("ADD VALUE LABELS Status '1' 'One' '' 'Empty'.");
        $payload['input_schema']['variables'] = [[
            'name' => 'Status', 'storage_kind' => 'string',
            'value_labels' => [
                ['value' => ['type' => 'string', 'value' => '01'], 'label' => 'Leading zero'],
                ['value' => ['type' => 'string', 'value' => '1'], 'label' => 'Old'],
            ],
        ]];
        $result = (new SpssCompiler())->compile(SpssFrontendRequest::fromArray($payload));
        self::assertSame([
            ['value' => ['type' => 'string', 'value' => '01'], 'label' => 'Leading zero'],
            ['value' => ['type' => 'string', 'value' => '1'], 'label' => 'One'],
            ['value' => ['type' => 'string', 'value' => ''], 'label' => 'Empty'],
        ], $result->plan->canonicalArray()['operations'][0]['labels']);
    }

    public function testCommentsRetainNormalizedHashQuotedMarkersAndExactDiagnosticCoordinates(): void
    {
        $source = " /* header */ * ignored ' quote.\r\nCOMMENT ignored \" quote.\rVARIABLE LABELS a '/* literal */ * COMMENT'.";
        $result = (new SpssCompiler())->compile(SpssFrontendRequest::fromArray(self::payload($source)));
        self::assertSame(hash('sha256', str_replace(["\r\n", "\r"], "\n", $source)), $result->sourceHash);
        self::assertSame('/* literal */ * COMMENT', $result->plan->canonicalArray()['operations'][0]['label']);
        try {
            (new SpssCompiler())->compile(SpssFrontendRequest::fromArray(self::payload("/* comment */\r\nCOMPUTE target = absent.")));
            self::fail('Unknown variable compiled.');
        } catch (TransformationFailure $failure) {
            self::assertSame('unknown_variable', $failure->diagnosticCode());
            $span = $failure->diagnostics[0]->span;
            self::assertNotNull($span);
            self::assertSame([32, 38, 2, 18], [$span->startOffset, $span->endOffset, $span->startLine, $span->startColumn]);
        }
    }

    #[DataProvider('rejectedSyntax')]
    public function testOfficialSyntaxFailsAtomically(string $source, string $code): void
    {
        try {
            (new SpssCompiler())->compile(SpssFrontendRequest::fromArray(self::payload('EXECUTE. ' . $source)));
            self::fail('Unsupported syntax compiled.');
        } catch (TransformationFailure $failure) {
            self::assertSame($code, $failure->diagnosticCode());
            self::assertNotNull($failure->diagnostics[0]->span);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedSyntax(): iterable
    {
        foreach (['/* nested /* inner */ */', '/* unclosed', '* no period', 'COMMENT no period', 'FORMATS a (F8.2) / .', "VARIABLE LABELS a 'A' / .", 'RECODE a (1 = 0) / .', 'RECODE a (LOWEST THRU 1e4000 = 0).', 'RECODE a (1 THRU LOWEST = 0).'] as $source) {
            yield $source => [$source, 'spss_syntax_error'];
        }
        yield 'reverse finite range' => ['RECODE a (2 THRU 1 = 0).', 'invalid_variable_range'];
        yield 'STRING' => ['STRING new (A8).', 'unsupported_spss_command'];
        yield 'DELETE' => ['DELETE VARIABLES a.', 'unsupported_spss_command'];
    }

    public function testExplicitSelectionDoesNotLeakIntoDefaultParserOrCompiler(): void
    {
        $compiler = new SpssCompiler();
        foreach (['* comment. EXECUTE.', 'COMMENT text. EXECUTE.', '/* comment */ EXECUTE.', 'IF (NOT a = 1) target = 1.', 'IF (a NE 1) target = 1.', 'IF (a <> 1) target = 1.', 'IF (a ~= 1) target = 1.', 'RECODE a (LOWEST THRU HIGHEST = 0).', "ADD VALUE LABELS a 1 'One'.", 'FORMATS a TO c (F8.2).', "VARIABLE LABELS a b 'Both'.", 'RECODE a (1 = 0) / b (1 = 0).'] as $source) {
            $compiler->compile(SpssFrontendRequest::fromArray(self::payload($source)));
            try {
                $compiler->compile(SpssFrontendRequest::fromArray([...self::payload($source), 'contract' => SpssFrontendRequest::CONTRACT]));
                self::fail('Default 0.2 accepted: ' . $source);
            } catch (TransformationFailure $failure) {
                self::assertNotNull($failure->diagnostics[0]->span);
            }
        }
        self::assertCount(1, $compiler->bind('parent', SpssFrontendRequest::fromArray(self::payload('EXECUTE.'))->inputSchema, $compiler->parse('EXECUTE.'))->statements);
    }

    /** @return array<string, mixed> */
    private static function payload(string $source): array
    {
        return [
            'contract' => SpssFrontendRequest::CONTRACT_V03,
            'input_alias' => 'parent',
            'input_schema' => ['variables' => array_map(static fn(string $name): array => ['name' => $name, 'storage_kind' => 'numeric'], ['a', 'b', 'c', 'target'])],
            'source_text' => $source,
        ];
    }
}
