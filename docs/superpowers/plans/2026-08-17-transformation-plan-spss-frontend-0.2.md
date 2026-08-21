# Transformation Plan and SPSS Frontend 0.2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Replace the package-local transformation API with official OpenStatSpec Transformation Plan 0.1/0.2, SPSS Frontend 0.2, and In-Place Transformation 0.1/0.2 conformance.

**Architecture:** Add a strict official plan model and codec beside the legacy model, migrate the SPSS compiler and in-place executor to it, then delete the legacy model before completion. The frontend compiles an alias-based request to a versioned canonical plan; a separate apply request binds that alias to one dataset and carries source hash, actor, and Dolt expectations.

**Tech Stack:** PHP 8.4.1+, PDO, PHPUnit 11, PHPStan 2, PHP-CS-Fixer 3, brick/math 0.19, OpenStatSpec specification v0.3.0 fixtures, SQLite/PostgreSQL/MySQL/MariaDB/Dolt.

## Global Constraints

- The breaking release target is v0.6.0; do not retain a compatibility adapter for openstatspec-transformation-plan-v1.
- Frontend 0.2 must still emit exact official Plan 0.1 for programs containing only the 0.1 command subset.
- Pass all 4 Plan 0.1, 26 Plan 0.2, 44 Frontend 0.2, 6 In-Place 0.1, and 11 In-Place 0.2 cases.
- Preserve dataset identity, physical table identity, case order, case count, dataset count, and persistent data-table count.
- Never create a derived dataset, persistent copy, snapshot, staging relation, rollback table, dataset-version row, or recovery layer.
- Dolt is the sole version/history/rollback layer; never commit, switch, merge, reset, or tag Dolt from the adapter.
- Validate the complete plan, target, actor, backend capabilities, and Dolt context before mutation.
- SQLite and PostgreSQL may create numeric targets atomically; MySQL, MariaDB, and Dolt must reject them with schema_change_not_atomic.
- Canonical hashes use exact restricted RFC 8785 bytes; decimal source numbers must round directly to binary64 ties-to-even without a host-float intermediate.
- Run every task through a red test, minimal implementation, focused green test, full relevant regression, and a focused commit.

---

## File Structure

New production boundaries:

- src/Transformation/Plan: official immutable plan, operation, expression, value, codec, and validation types.
- src/Transformation/Diagnostic: stable diagnostic value and exception types shared by plan, frontend, and executor.
- src/Frontend/Spss/Request: official frontend request and ordered input-schema types.
- src/Frontend/Spss/Number: exact finite-decimal to binary64 conversion.
- src/Transformation/Audit: explicit schema migration and compact transformation audit writer.
- src/Transformation/Execution: apply request, target binding, preflight, SQL predicate compiler, and executor.

New test boundaries:

- tests/Support/SpecificationManifest.php: one strict loader for the pinned sibling/CI specification checkout.
- tests/Transformation/Conformance: official Plan 0.1 and 0.2 golden tests.
- tests/Frontend/Spss/Conformance: official Frontend 0.2 golden tests, including exact referenced Plan 0.1 output.
- tests/Integration/OfficialInPlaceTransformation01Test.php: the 6 official 0.1 binding cases.
- tests/Integration/OfficialInPlaceTransformation02Test.php: the 11 official 0.2 binding cases.

---

### Task 1: Pin and load the official transformation manifests

**Files:**
- Create: tests/Support/SpecificationManifest.php
- Create: tests/Transformation/Conformance/ManifestInventoryTest.php
- Modify: tests/Release/SpecificationPinTest.php

**Interfaces:**
- Consumes: OPENSTATSPEC_SPECIFICATION_DIR or the workspace sibling specification directory.
- Produces: SpecificationManifest::load(string): array and SpecificationManifest::path(string): string.

- [ ] **Step 1: Write the failing manifest inventory test**

~~~php
public function testPinnedTransformationManifestInventory(): void
{
    self::assertCount(4, SpecificationManifest::load('conformance/transformation-plan-0.1.json')['cases']);
    self::assertCount(26, SpecificationManifest::load('conformance/transformation-plan-0.2.json')['cases']);
    self::assertCount(44, SpecificationManifest::load('conformance/spss-syntax-frontend-0.2.json')['cases']);
    self::assertCount(6, SpecificationManifest::load('conformance/in-place-transformation-0.1.json')['cases']);
    self::assertCount(11, SpecificationManifest::load('conformance/in-place-transformation-0.2.json')['cases']);
}
~~~

- [ ] **Step 2: Run the test and verify the missing loader failure**

Run: vendor/bin/phpunit tests/Transformation/Conformance/ManifestInventoryTest.php

Expected: FAIL because OpenStatSpec\Tests\Support\SpecificationManifest does not exist.

- [ ] **Step 3: Implement the strict loader**

~~~php
final class SpecificationManifest
{
    public static function path(string $relative): string
    {
        $configured = getenv('OPENSTATSPEC_SPECIFICATION_DIR');
        $root = is_string($configured) && $configured !== ''
            ? $configured
            : dirname(__DIR__, 3) . '/specification';
        $path = $root . '/' . ltrim($relative, '/');
        if (!is_file($path)) {
            throw new RuntimeException('Pinned specification fixture is missing: ' . $relative);
        }
        return $path;
    }

    /** @return array<string, mixed> */
    public static function load(string $relative): array
    {
        $decoded = json_decode(
            (string) file_get_contents(self::path($relative)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (!is_array($decoded)) {
            throw new RuntimeException('Specification manifest must decode to an object.');
        }
        return $decoded;
    }
}
~~~

- [ ] **Step 4: Extend the pin test to cover transformation schemas and manifests**

Assert that CapabilityDeclaration::SPECIFICATION_RELEASE remains v0.3.0, the commit remains cd8f198c68b849eb8ed018a894670a0904c2181d, and all five CI specification checkouts use that commit. Then assert the three used schema files (Plan 0.1, Plan 0.2, and Frontend 0.2) and five manifests used by this plan exist below the resolved root.

- [ ] **Step 5: Run focused and release tests**

Run: vendor/bin/phpunit tests/Transformation/Conformance/ManifestInventoryTest.php tests/Release/SpecificationPinTest.php

Expected: PASS with exact inventory counts and no skipped tests.

- [ ] **Step 6: Commit**

~~~bash
git add tests/Support/SpecificationManifest.php tests/Transformation/Conformance/ManifestInventoryTest.php tests/Release/SpecificationPinTest.php
git commit -m "test: load official transformation conformance manifests"
~~~

---

### Task 2: Implement the official Plan 0.1 model and canonical codec

**Files:**
- Create: src/Transformation/Diagnostic/TransformationDiagnostic.php
- Create: src/Transformation/Diagnostic/TransformationFailure.php
- Create: src/Transformation/Diagnostic/SourceSpan.php
- Create: src/Transformation/Plan/PlanContract.php
- Create: src/Transformation/Plan/TransformationPlan.php
- Create: src/Transformation/Plan/PlanCodec.php
- Create: src/Transformation/Plan/Operation.php
- Create: src/Transformation/Plan/TargetMode.php
- Create: src/Transformation/Plan/Value/TypedValue.php
- Create: src/Transformation/Plan/Value/Binary64Value.php
- Create: src/Transformation/Plan/Value/StringValue.php
- Create: src/Transformation/Plan/Recode/Match.php
- Create: src/Transformation/Plan/Recode/ExactMatch.php
- Create: src/Transformation/Plan/Recode/RangeMatch.php
- Create: src/Transformation/Plan/Recode/SystemMissingMatch.php
- Create: src/Transformation/Plan/Recode/Result.php
- Create: src/Transformation/Plan/Recode/LiteralResult.php
- Create: src/Transformation/Plan/Recode/CopyResult.php
- Create: src/Transformation/Plan/Recode/SystemMissingResult.php
- Create: src/Transformation/Plan/Recode/RecodeRule.php
- Create: src/Transformation/Plan/Operation/RecodeOperation.php
- Create: src/Transformation/Plan/Operation/SetVariableLabelOperation.php
- Create: src/Transformation/Plan/Operation/ReplaceValueLabelsOperation.php
- Create: src/Transformation/Plan/Operation/ValueLabel.php
- Create: tests/Transformation/Conformance/TransformationPlan01Test.php

**Interfaces:**
- Consumes: CanonicalJson::encode(array): string and SpecificationManifest::load(string): array.
- Produces: PlanCodec::fromArray(array): TransformationPlan, PlanCodec::fromJson(string): TransformationPlan, PlanCodec::canonicalJson(TransformationPlan): string, and PlanCodec::hash(TransformationPlan): string.

- [ ] **Step 1: Write the failing Plan 0.1 golden test**

~~~php
#[DataProvider('cases')]
public function testOfficialPlan01(array $case): void
{
    if (is_string($case['expected_error'])) {
        try {
            (new PlanCodec())->fromArray($case['plan']);
            self::fail('Expected ' . $case['expected_error']);
        } catch (TransformationFailure $failure) {
            self::assertSame($case['expected_error'], $failure->diagnosticCode());
        }
        return;
    }

    $plan = (new PlanCodec())->fromArray($case['plan']);
    self::assertSame($case['plan'], $plan->canonicalArray());
    self::assertSame($case['expected_plan_hash'], (new PlanCodec())->hash($plan));
}
~~~

- [ ] **Step 2: Run the test and verify it is red**

Run: vendor/bin/phpunit tests/Transformation/Conformance/TransformationPlan01Test.php

Expected: FAIL because PlanCodec and the official plan model do not exist.

- [ ] **Step 3: Add stable diagnostics and the contract root**

~~~php
enum PlanContract: string
{
    case V01 = 'openstatspec-transformation-plan-v0.1';
    case V02 = 'openstatspec-transformation-plan-v0.2';
}

interface Operation
{
    /** @return array<string, mixed> */
    public function canonicalArray(): array;
    public function minimumContract(): PlanContract;
}

final readonly class TransformationPlan
{
    /** @param non-empty-list<Operation> $operations */
    public function __construct(
        public PlanContract $contract,
        public string $inputAlias,
        public array $operations,
    ) {}

    /** @return array{contract:string,input_alias:string,operations:list<array<string,mixed>>} */
    public function canonicalArray(): array
    {
        return [
            'contract' => $this->contract->value,
            'input_alias' => $this->inputAlias,
            'operations' => array_map(
                static fn(Operation $operation): array => $operation->canonicalArray(),
                $this->operations,
            ),
        ];
    }
}
~~~

The non-final TransformationFailure stores a non-empty list of TransformationDiagnostic and exposes diagnosticCode() as the first stable code. TransformationFailure::at(string $code, string $path, string $message, ?SourceSpan $span = null) creates a one-diagnostic failure. Diagnostic values carry code, JSON path, message, and the optional shared SourceSpan without credentials or row values; SpssSyntaxException extends this shared failure type in Task 5.

- [ ] **Step 4: Implement exact typed values and 0.1 operation shapes**

Use immutable classes whose canonicalArray methods emit exactly these keys:

~~~php
new RecodeOperation(
    source: 'q1',
    target: 'q1',
    targetMode: TargetMode::Replace,
    rules: [new RecodeRule(new ExactMatch(Binary64Value::fromBits('3ff0000000000000')), new LiteralResult(Binary64Value::fromBits('0000000000000000')))],
    unmatched: new CopyResult(),
);

// canonicalArray()
[
    'op' => 'recode',
    'source' => 'q1',
    'target' => 'q1',
    'target_mode' => 'replace',
    'rules' => [['match' => ['kind' => 'exact', 'value' => ['type' => 'binary64', 'bits' => '3ff0000000000000']], 'result' => ['kind' => 'literal', 'value' => ['type' => 'binary64', 'bits' => '0000000000000000']]]],
    'unmatched' => ['kind' => 'copy'],
];
~~~

SetVariableLabelOperation emits op, variable, label. ReplaceValueLabelsOperation emits op, variable, and ordered labels; each label emits value then label. Binary64Value accepts exactly 16 lowercase hexadecimal bits, rejects exponent 0x7ff and negative zero with plan_schema_invalid, and StringValue preserves exact Unicode.

- [ ] **Step 5: Implement strict recursive PlanCodec decoding**

PlanCodec must reject missing/extra members, empty aliases/operations, unknown operations, malformed typed values, descending ranges, and duplicate exact typed label values. Map the two semantic 0.1 failures to invalid_numeric_range and duplicate_value_label; all structural failures map to plan_schema_invalid.

~~~php
public function canonicalJson(TransformationPlan $plan): string
{
    return CanonicalJson::encode($plan->canonicalArray());
}

public function hash(TransformationPlan $plan): string
{
    return hash('sha256', $this->canonicalJson($plan));
}
~~~

- [ ] **Step 6: Run Plan 0.1 goldens and canonical regressions**

Run: vendor/bin/phpunit tests/Transformation/Conformance/TransformationPlan01Test.php tests/Transformation/Canonical/TransformationPlanTest.php

Expected: all four official 0.1 cases pass; legacy tests remain green because their namespace has not been removed yet.

- [ ] **Step 7: Run static checks and commit**

Run: composer analyse && composer style

~~~bash
git add src/Transformation/Diagnostic src/Transformation/Plan tests/Transformation/Conformance/TransformationPlan01Test.php
git commit -m "feat: add official transformation plan 0.1 model"
~~~

---

### Task 3: Extend the model and codec to Plan 0.2

**Files:**
- Create: src/Transformation/Plan/Expression/Operand.php
- Create: src/Transformation/Plan/Expression/LiteralOperand.php
- Create: src/Transformation/Plan/Expression/VariableOperand.php
- Create: src/Transformation/Plan/Expression/Predicate.php
- Create: src/Transformation/Plan/Expression/Comparison.php
- Create: src/Transformation/Plan/Expression/BooleanPredicate.php
- Create: src/Transformation/Plan/Operation/AssignOperation.php
- Create: src/Transformation/Plan/Operation/ConditionalAssignOperation.php
- Create: src/Transformation/Plan/Operation/SetFormatOperation.php
- Create: src/Transformation/Plan/Operation/SetMeasurementLevelOperation.php
- Create: src/Transformation/Plan/Operation/ExecuteOperation.php
- Modify: src/Transformation/Plan/PlanCodec.php
- Create: tests/Transformation/Conformance/TransformationPlan02Test.php
- Create: tests/Transformation/Plan/BooleanPredicateTest.php

**Interfaces:**
- Consumes: official Plan 0.1 model and codec.
- Produces: typed 0.2 operations and predicates accepted by the same PlanCodec API.

- [ ] **Step 1: Write the failing 26-case Plan 0.2 golden test**

Reuse the Task 2 data-provider structure with conformance/transformation-plan-0.2.json. Add a focused assertion that a direct BooleanPredicate child with the same operator throws noncanonical_boolean_shape.

- [ ] **Step 2: Run the tests and verify unsupported 0.2 operations fail**

Run: vendor/bin/phpunit tests/Transformation/Conformance/TransformationPlan02Test.php tests/Transformation/Plan/BooleanPredicateTest.php

Expected: FAIL on the first assign or conditional_assign case.

- [ ] **Step 3: Implement operands and predicates**

~~~php
final readonly class Comparison implements Predicate
{
    public function __construct(
        public Operand $left,
        public string $operator,
        public Operand $right,
    ) {
        if (!in_array($operator, ['=', '<', '<=', '>', '>='], true)) {
            throw TransformationFailure::at('plan_schema_invalid', '$.operator', 'Unsupported comparison operator.');
        }
    }

    public function canonicalArray(): array
    {
        return [
            'expression' => 'comparison',
            'left' => $this->left->canonicalArray(),
            'operator' => $this->operator,
            'right' => $this->right->canonicalArray(),
        ];
    }
}
~~~

BooleanPredicate accepts and/or plus at least two ordered operands. Reject a direct BooleanPredicate child with the same operator as noncanonical_boolean_shape; never reorder, deduplicate, simplify, distribute, or constant-fold.

- [ ] **Step 4: Implement the five 0.2 operations**

AssignOperation emits op, target, target_mode, value. ConditionalAssignOperation emits op, condition, target, value. SetFormatOperation emits op, variable, family F, width, decimals. SetMeasurementLevelOperation emits op, variable, level. ExecuteOperation emits only op.

Validation rules:

~~~php
if ($operation instanceof SetFormatOperation
    && ($operation->width < 1 || $operation->width > 40
        || $operation->decimals < 0 || $operation->decimals > 16
        || ($operation->decimals > 0 && $operation->width < $operation->decimals + 2))) {
    throw TransformationFailure::at('invalid_format', $path, 'Invalid SPSS F format.');
}
~~~

Reject string literal operands in assign, conditional assignment, or comparison with expression_type_unsupported; reject names beginning with double underscore using reserved_target_name where the name is a target.

- [ ] **Step 5: Enforce contract selection**

PlanContract::V01 accepts only operations whose minimumContract is V01. PlanContract::V02 accepts both 0.1 and 0.2 operations. Decoding never promotes or rewrites a declared 0.1 plan.

- [ ] **Step 6: Run all official plan tests**

Run: vendor/bin/phpunit tests/Transformation/Conformance tests/Transformation/Plan

Expected: 4 Plan 0.1 and 26 Plan 0.2 cases pass with exact hashes and stable expected errors.

- [ ] **Step 7: Run static checks and commit**

Run: composer analyse && composer style

~~~bash
git add src/Transformation/Plan tests/Transformation/Conformance/TransformationPlan02Test.php tests/Transformation/Plan
git commit -m "feat: implement transformation plan 0.2 operations"
~~~

---

### Task 4: Convert SPSS finite decimals to binary64 exactly

**Files:**
- Modify: composer.json
- Modify: composer.lock
- Create: src/Frontend/Spss/Number/DecimalBinary64.php
- Create: tests/Frontend/Spss/DecimalBinary64Test.php

**Interfaces:**
- Consumes: brick/math ^0.19 BigInteger arithmetic.
- Produces: DecimalBinary64::bits(string): string returning exactly 16 lowercase hexadecimal digits.

- [ ] **Step 1: Add the dependency and failing boundary tests**

Run: composer require brick/math:^0.19 --no-interaction

~~~php
#[DataProvider('tokens')]
public function testExactBits(string $token, string $bits): void
{
    self::assertSame($bits, DecimalBinary64::bits($token));
}

public static function tokens(): iterable
{
    yield 'negative zero' => ['-0', '0000000000000000'];
    yield 'underflowed zero' => ['-1e-9999', '0000000000000000'];
    yield 'minimum subnormal' => ['4.9406564584124654417656879286822137236505980e-324', '0000000000000001'];
    yield 'one' => ['1', '3ff0000000000000'];
    yield 'tie rounds even' => ['1.00000000000000011102230246251565404236316680908203125', '3ff0000000000000'];
}
~~~

Add rejection tests for +1, .5, 1., 01, NaN, Infinity, and overflow 1e9999.

- [ ] **Step 2: Run the test and verify it is red**

Run: vendor/bin/phpunit tests/Frontend/Spss/DecimalBinary64Test.php

Expected: FAIL because DecimalBinary64 does not exist.

- [ ] **Step 3: Implement grammar and exact rational construction**

Parse the token with this exact pattern:

~~~php
private const FINITE = '/\A(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?\z/D';
~~~

Build coefficient and decimal exponent with BigInteger only. Represent the absolute value as numerator/denominator where positive decimal exponents multiply the numerator by 10^n and negative exponents multiply the denominator by 10^n.

- [ ] **Step 4: Implement ties-to-even rounding**

~~~php
private static function roundRatio(BigInteger $numerator, BigInteger $denominator): BigInteger
{
    [$quotient, $remainder] = $numerator->quotientAndRemainder($denominator);
    $twice = $remainder->multipliedBy(2);
    $comparison = $twice->compareTo($denominator);
    if ($comparison > 0 || ($comparison === 0 && $quotient->isOdd())) {
        return $quotient->plus(1);
    }
    return $quotient;
}
~~~

Find floor(log2(numerator/denominator)) by integer comparison. For normal values round numerator multiplied by 2^(52-e); for subnormals round numerator multiplied by 2^1074. Handle significand carry, overflow, sign bit, minimum normal, and zero before rendering a 64-bit hexadecimal word.

- [ ] **Step 5: Run focused and frontend-number tests**

Run: vendor/bin/phpunit tests/Frontend/Spss/DecimalBinary64Test.php

Expected: all accepted tokens produce exact bits and every rejected token throws spss_syntax_error.

- [ ] **Step 6: Run quality checks and commit**

Run: composer validate --strict && composer analyse && composer style

~~~bash
git add composer.json composer.lock src/Frontend/Spss/Number/DecimalBinary64.php tests/Frontend/Spss/DecimalBinary64Test.php
git commit -m "feat: add exact SPSS decimal binary64 conversion"
~~~

---

### Task 5: Parse the official SPSS Frontend 0.2 grammar

**Files:**
- Create: src/Frontend/Spss/Request/SpssFrontendRequest.php
- Create: src/Frontend/Spss/Request/InputSchema.php
- Create: src/Frontend/Spss/Request/InputVariable.php
- Create: src/Frontend/Spss/Ast/ComputeStatement.php
- Create: src/Frontend/Spss/Ast/IfStatement.php
- Create: src/Frontend/Spss/Ast/FormatsStatement.php
- Create: src/Frontend/Spss/Ast/FormatTarget.php
- Create: src/Frontend/Spss/Ast/VariableLevelStatement.php
- Create: src/Frontend/Spss/Ast/VariableLevelGroup.php
- Create: src/Frontend/Spss/Ast/ExpressionOperand.php
- Create: src/Frontend/Spss/Ast/LiteralOperand.php
- Create: src/Frontend/Spss/Ast/VariableOperand.php
- Create: src/Frontend/Spss/Ast/Predicate.php
- Create: src/Frontend/Spss/Ast/Comparison.php
- Create: src/Frontend/Spss/Ast/BooleanPredicate.php
- Modify: src/Frontend/Spss/TokenType.php
- Modify: src/Frontend/Spss/Lexer.php
- Modify: src/Frontend/Spss/Parser.php
- Modify: src/Frontend/Spss/SpssSyntaxException.php
- Modify: tests/Frontend/Spss/LexerTest.php
- Modify: tests/Frontend/Spss/ParserTest.php

**Interfaces:**
- Consumes: exact numeric-token grammar and DecimalBinary64 rejection behavior.
- Produces: Parser::parse(string): Program containing ordered 0.1/0.2 statement AST nodes with source spans.

- [ ] **Step 1: Write failing parser tests for every new command**

~~~php
$program = (new Parser())->parse(
    "COMPUTE target = 0.\n"
    . "IF (a = 1 AND b >= 2 OR c < 3) target = a.\n"
    . "FORMATS target (F8.2).\n"
    . "VARIABLE LEVEL target (SCALE).\n"
    . "EXECUTE."
);

self::assertInstanceOf(ComputeStatement::class, $program->statements[0]);
self::assertInstanceOf(IfStatement::class, $program->statements[1]);
self::assertInstanceOf(FormatsStatement::class, $program->statements[2]);
self::assertInstanceOf(VariableLevelStatement::class, $program->statements[3]);
self::assertInstanceOf(ExecuteStatement::class, $program->statements[4]);
~~~

Add rejection tests for IF without outer parentheses, IF ELSE, arithmetic, NOT, string expressions, non-F formats, invalid levels, comments, and unsupported commands. Assert each exception exposes the stable code and start/end source span.

- [ ] **Step 2: Run parser tests and verify they are red**

Run: vendor/bin/phpunit tests/Frontend/Spss/LexerTest.php tests/Frontend/Spss/ParserTest.php

Expected: FAIL on COMPUTE tokenization.

- [ ] **Step 3: Extend tokens and lexer**

Add ASCII-case-insensitive keyword tokens for COMPUTE, IF, AND, OR, FORMATS, VARIABLE LEVEL, NOMINAL, ORDINAL, and SCALE; add equals and ordered comparison tokens. Preserve the exact source offset plus 1-based line/column at both token boundaries.

- [ ] **Step 4: Implement precedence and flattening in the parser**

~~~php
private function parsePredicate(): Predicate
{
    return $this->parseOr();
}

private function merge(string $operator, Predicate $left, Predicate $right): BooleanPredicate
{
    $operands = $left instanceof BooleanPredicate && $left->operator === $operator
        ? $left->operands
        : [$left];
    array_push($operands, ...($right instanceof BooleanPredicate && $right->operator === $operator
        ? $right->operands
        : [$right]));
    return new BooleanPredicate($operator, $operands, SourceSpan::cover($left->span, $right->span));
}
~~~

parseOr calls parseAnd; parseAnd calls comparison; comparison parses two operands. Parentheses recurse but same-operator nodes are flattened in source order. IF requires one outer parenthesized predicate.

- [ ] **Step 5: Implement grouped metadata commands and stable failures**

FORMATS emits ordered FormatTarget pairs with uppercase F and integer width/decimals. VARIABLE LEVEL emits ordered groups and lowercase levels. Invalid productions throw spss_syntax_error; recognized but unsupported commands throw unsupported_spss_command.

- [ ] **Step 6: Run lexer/parser regression tests**

Run: vendor/bin/phpunit tests/Frontend/Spss/LexerTest.php tests/Frontend/Spss/ParserTest.php

Expected: all old official-subset parsing and new 0.2 parsing tests pass.

- [ ] **Step 7: Run static checks and commit**

Run: composer analyse && composer style

~~~bash
git add src/Frontend/Spss tests/Frontend/Spss/LexerTest.php tests/Frontend/Spss/ParserTest.php
git commit -m "feat: parse SPSS frontend 0.2 commands"
~~~

---

### Task 6: Bind and compile all official Frontend 0.2 cases

**Files:**
- Create: src/Frontend/Spss/SpssCompilationResult.php
- Create: src/Frontend/Spss/Binding/SchemaState.php
- Create: src/Frontend/Spss/Binding/BoundCompute.php
- Create: src/Frontend/Spss/Binding/BoundConditionalAssign.php
- Create: src/Frontend/Spss/Binding/BoundFormat.php
- Create: src/Frontend/Spss/Binding/BoundMeasurementLevel.php
- Create: src/Frontend/Spss/Binding/BoundExecute.php
- Modify: src/Frontend/Spss/Binder.php
- Modify: src/Frontend/Spss/Compiler.php
- Modify: src/Frontend/Spss/SpssCompiler.php
- Create: tests/Frontend/Spss/Conformance/SpssFrontend02Test.php
- Modify: tests/Frontend/Spss/SpssCompilerTest.php

**Interfaces:**
- Consumes: SpssFrontendRequest, Parser AST, DecimalBinary64, and official plan types.
- Produces: SpssFrontendRequest::sourceHash(): string and SpssCompiler::compile(SpssFrontendRequest): SpssCompilationResult with public readonly plan and sourceHash.

- [ ] **Step 1: Write failing manifest-driven frontend tests**

~~~php
$request = SpssFrontendRequest::fromArray($case['request']);
self::assertSame($case['expected_source_hash'], $request->sourceHash());
try {
    $result = (new SpssCompiler())->compile($request);
} catch (TransformationFailure $failure) {
    self::assertSame($case['expected_error'], $failure->diagnosticCode());
    return;
}

self::assertNull($case['expected_error']);
self::assertSame($case['expected_source_hash'], $result->sourceHash);
self::assertSame($case['expected_plan_hash'], (new PlanCodec())->hash($result->plan));
if (isset($case['expected_plan_contract'])) {
    self::assertSame($case['expected_plan_contract'], $result->plan->contract->value);
}
~~~

Load the official 0.2 frontend manifest; references to exact 0.1 plans are checked against conformance/transformation-plan-0.1.json rather than copied expectations.

- [ ] **Step 2: Run the frontend conformance tests and verify red**

Run: vendor/bin/phpunit tests/Frontend/Spss/Conformance

Expected: FAIL because SpssFrontendRequest and the new compiler signature do not exist.

- [ ] **Step 3: Validate the request and source hash**

SpssFrontendRequest::fromArray accepts exactly contract, input_alias, input_schema, and source_text; contract must equal openstatspec-spss-syntax-frontend-v0.2. InputSchema requires a non-empty ordered variables array; InputVariable validates name, storage_kind, and optional label/value-label/format/measurement fields.

~~~php
$normalized = str_replace(["\r\n", "\r"], "\n", $request->sourceText);
$sourceHash = hash('sha256', $normalized);
~~~

sourceHash() performs this normalization independently of parsing so failed compilations still have the exact conformance source identity.

- [ ] **Step 4: Implement case-insensitive evolving schema binding**

SchemaState indexes ASCII-lowercase names while retaining exact catalog spelling. RECODE pairs resolve against pre-command state; targets created by an earlier command become available to later commands. COMPUTE creates or replaces a numeric target. IF requires an existing numeric target. FORMATS requires numeric targets; VARIABLE LEVEL accepts numeric or string targets.

Map binding failures exactly to unknown_variable, conditional_target_missing, type_mismatch, expression_type_unsupported, invalid_format, reserved_target_name, duplicate_value_label, mixed_result_types, system_missing_for_string, and string_target_requires_declaration.

- [ ] **Step 5: Compile ordered operations and select the plan contract**

~~~php
$contract = array_any(
    $operations,
    static fn(Operation $operation): bool => $operation->minimumContract() === PlanContract::V02,
) ? PlanContract::V02 : PlanContract::V01;

$plan = new TransformationPlan($contract, $request->inputAlias, $operations);
return new SpssCompilationResult($plan, $sourceHash);
~~~

Compile DecimalBinary64 bits directly into Binary64Value. Preserve command, group, variable, rule, and boolean operand order. EXECUTE always remains an ExecuteOperation.

- [ ] **Step 6: Run all 44 official Frontend 0.2 cases**

Run: vendor/bin/phpunit tests/Frontend/Spss/Conformance tests/Frontend/Spss/SpssCompilerTest.php

Expected: all 44 Frontend 0.2 cases pass with exact plan/source hashes and stable errors, including the required exact Plan 0.1 outputs.

- [ ] **Step 7: Run plan plus frontend regressions and commit**

Run: vendor/bin/phpunit tests/Transformation/Conformance tests/Frontend/Spss
Run: composer analyse && composer style

~~~bash
git add src/Frontend/Spss tests/Frontend/Spss
git commit -m "feat: compile official SPSS frontend 0.2 plans"
~~~

---

### Task 7: Add the explicit transformation audit migration and apply request

**Files:**
- Create: src/Transformation/Execution/InPlaceApplyRequest.php
- Create: src/Transformation/Audit/TransformationAuditMigrator.php
- Create: src/Transformation/Audit/TransformationAuditWriter.php
- Modify: src/Sql/CatalogOwnership.php
- Modify: src/Sql/NormativeCatalog.php
- Modify: src/Spss/SpssAdapter.php
- Create: tests/Transformation/Audit/TransformationAuditMigratorTest.php
- Modify: tests/Integration/ServerCatalogMigrationTest.php

**Interfaces:**
- Consumes: TransformationPlan and PlanCodec.
- Produces: InPlaceApplyRequest(plan, inputAlias, datasetId, sourceHash, actor, expectedBranch, expectedHead) and TransformationAuditWriter::succeed(...): string.

- [ ] **Step 1: Write failing SQLite migration and request-validation tests**

~~~php
$request = new InPlaceApplyRequest(
    plan: $plan,
    inputAlias: 'parent',
    datasetId: '11111111-1111-4111-8111-111111111111',
    sourceHash: str_repeat('a', 64),
    actor: 'conformance-runner',
);
self::assertSame('parent', $request->inputAlias);

(new TransformationAuditMigrator($pdo))->migrate();
self::assertSame(
    ['openstatspec-in-place-transformation-v0.1', 'openstatspec-in-place-transformation-v0.2'],
    $this->acceptedContracts($pdo),
);
~~~

Also create an old 0.1-only transformation_apply table with one row, migrate it, and assert the row and logical table name survive with no backup table residue.

- [ ] **Step 2: Run tests and verify red**

Run: vendor/bin/phpunit tests/Transformation/Audit/TransformationAuditMigratorTest.php

Expected: FAIL because the request and migrator do not exist.

- [ ] **Step 3: Implement fail-fast apply request validation**

The constructor rejects alias mismatch, malformed dataset UUID, malformed source hash, and empty actor before any database call. expectedBranch and expectedHead must be both null or both non-empty.

~~~php
if ($inputAlias !== $plan->inputAlias) {
    throw TransformationFailure::at('unknown_input_alias', '$.input_alias', 'Apply binding does not match the plan alias.');
}
if (trim($actor) === '') {
    throw TransformationFailure::at('actor_required', '$.actor', 'A non-empty actor is required.');
}
~~~

- [ ] **Step 4: Implement schema version 4 migration**

Bump CatalogOwnership::SCHEMA_VERSION from 3 to 4. TransformationAuditMigrator creates the exact normative columns and checks from sql/transformation-plan-profile-schema.sql using profile-specific UUID/text types.

For SQLite 0.1-only tables: begin a native transaction, create transformation_apply_v04, copy compact audit rows, drop the old table, rename the new table to transformation_apply, verify foreign keys, and commit. On failure roll back so the old table remains. PostgreSQL alters the contract check transactionally. MySQL/MariaDB/Dolt replace the check in the explicit migration workflow before applies; never perform this DDL inside an apply.

- [ ] **Step 5: Implement the success-only audit writer**

~~~php
public function succeed(
    InPlaceApplyRequest $request,
    DatasetBinding $dataset,
    string $databaseProfile,
    ?DoltEvidence $before,
    ?DoltEvidence $after,
): string
~~~

Insert exactly one succeeded row inside the open apply transaction after operations and post-Dolt checks but before commit. Store canonical plan JSON, plan/source hashes, actor, operation count, target identity, timestamps, and nullable Dolt fields. Do not write a failed row after rollback because the 0.2 binding requires failed applies to leave audit unchanged.

- [ ] **Step 6: Wire explicit catalog migration**

SpssAdapter::migrateCatalog calls TransformationAuditMigrator after NormativeCatalog::createTables and before CatalogOwnership::markCurrentVersion. Fresh catalogs receive migration markers 1 through 4 exactly once.

- [ ] **Step 7: Run SQLite and configured server migration tests**

Run: vendor/bin/phpunit tests/Transformation/Audit/TransformationAuditMigratorTest.php tests/Integration/ServerCatalogMigrationTest.php

Expected: SQLite always passes; configured servers pass; unconfigured services are the only skips.

- [ ] **Step 8: Run static checks and commit**

Run: composer analyse && composer style

~~~bash
git add src/Transformation/Audit src/Transformation/Execution/InPlaceApplyRequest.php src/Sql/CatalogOwnership.php src/Sql/NormativeCatalog.php src/Spss/SpssAdapter.php tests/Transformation/Audit tests/Integration/ServerCatalogMigrationTest.php
git commit -m "feat: add official transformation apply audit"
~~~

---

### Task 8: Execute official plans in place on SQLite and PostgreSQL

**Files:**
- Create: src/Transformation/Execution/PlanPreflight.php
- Create: src/Transformation/Execution/BoundPlan.php
- Create: src/Transformation/Execution/BoundOperation.php
- Create: src/Transformation/Execution/SqlPredicateCompiler.php
- Create: src/Transformation/Execution/InPlaceOperationExecutor.php
- Modify: src/Transformation/Execution/InPlaceTransformationExecutor.php
- Modify: src/Transformation/Execution/ExecutionResult.php
- Modify: src/Transformation/Execution/DatasetBinding.php
- Modify: src/Transformation/Execution/VariableBinding.php
- Modify: tests/Transformation/Execution/InPlaceTransformationExecutorTest.php
- Modify: tests/Integration/InPlaceTransformationServiceTest.php
- Create: tests/Integration/OfficialInPlaceTransformation01Test.php
- Create: tests/Integration/OfficialInPlaceTransformation02Test.php

**Interfaces:**
- Consumes: InPlaceApplyRequest, official plan operations, Connection profile, and TransformationAuditWriter.
- Produces: InPlaceTransformationExecutor::execute(InPlaceApplyRequest): ExecutionResult.

- [ ] **Step 1: Add failing SQLite official binding cases**

In OfficialInPlaceTransformation02Test, materialize fixtures for sqlite-create-target-atomic-success and sqlite-inequality-boundary-semantics from the manifest. Snapshot dataset count, persistent data-table count, dataset/table identity, case order/count, variables, metadata, and audit count before apply.

~~~php
$result = (new InPlaceTransformationExecutor(new Connection($pdo)))->execute($request);
self::assertSame($case['expected_audit']['plan_hash'], $result->planHash());
self::assertSame($before['dataset_count'], $this->datasetCount($pdo));
self::assertSame($before['persistent_data_table_count'], $this->persistentDataTableCount($pdo));
self::assertSame($case['after']['rows'], $this->rows($pdo, $table));
~~~

Add the rollback probe that injects failure after catalog target creation but before audit and asserts no physical column, catalog variable, row change, or audit row remains.

- [ ] **Step 2: Run focused tests and verify red**

Run: vendor/bin/phpunit tests/Integration/OfficialInPlaceTransformation02Test.php --filter sqlite

Expected: FAIL because the executor still accepts the legacy plan signature.

- [ ] **Step 3: Implement complete preflight**

PlanPreflight resolves the alias-bound dataset and ordered catalog variables, then simulates every operation against an evolving schema. It validates unknown variables, create/replace target state, numeric expression types, recode result type, format validity, backend create capability, and audit schema readiness before transaction start.

Return an immutable BoundPlan containing the unchanged plan plus per-operation source/target physical bindings. Do not store row values or create database objects during preflight.

- [ ] **Step 4: Implement deterministic SQL predicate compilation**

~~~php
private function comparison(Comparison $comparison, BoundSchema $schema, array &$parameters): string
{
    $left = $this->operand($comparison->left, $schema, $parameters);
    $right = $this->operand($comparison->right, $schema, $parameters);
    return '(' . $left . ' ' . $comparison->operator . ' ' . $right . ')';
}

private function boolean(BooleanPredicate $predicate, BoundSchema $schema, array &$parameters): string
{
    return '(' . implode(
        ' ' . strtoupper($predicate->operator) . ' ',
        array_map(fn(Predicate $child): string => $this->predicate($child, $schema, $parameters), $predicate->operands),
    ) . ')';
}
~~~

Literal operands append a PDO parameter; variable operands use the profile-quoted physical column. SQL NULL comparisons naturally become UNKNOWN; conditional UPDATE changes only rows whose WHERE predicate is TRUE.

- [ ] **Step 5: Implement ordered operation execution**

InPlaceOperationExecutor receives one BoundOperation at a time so the public InPlaceTransformationExecutor remains a small transaction orchestrator. Use direct UPDATE for existing recode/assign, ALTER TABLE plus catalog insert plus UPDATE for atomic create, conditional UPDATE WHERE predicate, exact catalog updates for labels/format/measurement, and no SQL for execute. Each operation observes prior operations in the same transaction.

SetFormatOperation updates print and write family/width/decimals only. SetMeasurementLevelOperation updates measurement_level only. New variables receive a unique UUID, next ordinal, nullable numeric column, and no label/value-label/missing/display/measurement metadata.

- [ ] **Step 6: Put audit in the same transaction**

Begin one native transaction after preflight, apply every operation, run post-context checks, insert the success audit, then commit. Any Throwable rolls back; do not start OperationJournal for transformations.

- [ ] **Step 7: Add PostgreSQL atomic-create evidence**

Extend OfficialInPlaceTransformation02Test to run the SQLite create-target case against OPENSTATSPEC_PG_DSN with PostgreSQL identifiers and catalog fixture setup. Assert schema, rows, catalog, audit, and rollback probe share the transaction and leave no extra persistent relation.

- [ ] **Step 8: Run executor regressions**

Run: vendor/bin/phpunit tests/Transformation/Execution/InPlaceTransformationExecutorTest.php tests/Integration/InPlaceTransformationServiceTest.php tests/Integration/OfficialInPlaceTransformation01Test.php tests/Integration/OfficialInPlaceTransformation02Test.php

Expected: SQLite cases pass, PostgreSQL passes when configured, and only unconfigured services skip.

- [ ] **Step 9: Run static checks and commit**

Run: composer analyse && composer style

~~~bash
git add src/Transformation/Execution tests/Transformation/Execution tests/Integration/InPlaceTransformationServiceTest.php tests/Integration/OfficialInPlaceTransformation01Test.php tests/Integration/OfficialInPlaceTransformation02Test.php
git commit -m "feat: execute official plans atomically in place"
~~~

---

### Task 9: Enforce MySQL-family and controlled Dolt bindings

**Files:**
- Modify: src/Transformation/Execution/DoltGuard.php
- Modify: src/Transformation/Execution/DoltEvidence.php
- Modify: src/Transformation/Execution/PdoDoltEvidenceReader.php
- Modify: src/Transformation/Execution/PlanPreflight.php
- Modify: src/Transformation/Execution/InPlaceTransformationExecutor.php
- Modify: tests/Transformation/Execution/DoltGuardTest.php
- Modify: tests/Transformation/Execution/DoltHeadGuardTest.php
- Modify: tests/Integration/OfficialInPlaceTransformation01Test.php
- Modify: tests/Integration/OfficialInPlaceTransformation02Test.php
- Modify: tests/Integration/InPlaceTransformationServiceTest.php

**Interfaces:**
- Consumes: InPlaceApplyRequest expected branch/HEAD and Connection::profileName.
- Produces: DoltGuard::beforeExecution(InPlaceApplyRequest): DoltEvidence and afterExecution(InPlaceApplyRequest, DoltEvidence): DoltEvidence.

- [ ] **Step 1: Add failing backend rejection tests**

For MySQL, MariaDB, and Dolt create-target manifest cases, snapshot data, catalog, audit, dataset count, and persistent table count. Execute the create plan and assert schema_change_not_atomic plus mutation_started=false and exact before/after equality. Include the official 0.1 MySQL create-target rejection as well as the 0.2 cases.

- [ ] **Step 2: Add failing Dolt context tests**

Cover empty actor, missing expected context, branch mismatch, HEAD mismatch, dirty working set, and injected post-mutation context change. Assert exact codes: actor_required, dolt_context_required, dolt_branch_mismatch, dolt_head_mismatch, dolt_working_set_dirty, and dolt_context_changed.

- [ ] **Step 3: Run focused tests and verify red**

Run: vendor/bin/phpunit tests/Transformation/Execution/DoltGuardTest.php tests/Transformation/Execution/DoltHeadGuardTest.php tests/Integration/OfficialInPlaceTransformation01Test.php tests/Integration/OfficialInPlaceTransformation02Test.php

Expected: FAIL because current DoltGuard compares only its own before/after observations.

- [ ] **Step 4: Enforce profile capabilities during preflight**

~~~php
if ($operation->createsTarget() && !$connection->profile->ddlAtomic()) {
    throw TransformationFailure::at(
        'schema_change_not_atomic',
        '$.operations[' . $index . '].target_mode',
        $connection->profileName . ' requires a pre-provisioned target.',
    );
}
~~~

This runs before beginning a transaction or writing audit. MySQL, MariaDB, and Dolt never attempt compensating transformation cleanup.

- [ ] **Step 5: Enforce caller-supplied Dolt context**

Before mutation, require expectedBranch and expectedHead, compare them to active_branch() and dolt_hashof('HEAD'), and require dolt_status to be empty. After operations but before audit/commit, re-read branch and HEAD and compare them to both the request and before evidence.

Never call a Dolt mutation procedure. A successful apply leaves one inspectable dirty working set and equal before/after HEAD in the audit.

- [ ] **Step 6: Run all official 0.1 and 0.2 in-place cases**

Run: vendor/bin/phpunit tests/Integration/OfficialInPlaceTransformation01Test.php tests/Integration/OfficialInPlaceTransformation02Test.php

Expected: all 6 official 0.1 and 11 official 0.2 cases for configured services pass; only absent service configurations skip.

- [ ] **Step 7: Run the existing service evidence test**

Run: vendor/bin/phpunit tests/Integration/InPlaceTransformationServiceTest.php

Expected: every existing-target service case preserves the global no-new-dataset/table invariant; live Dolt evidence proves no commit and stable branch/HEAD.

- [ ] **Step 8: Run static checks and commit**

Run: composer analyse && composer style

~~~bash
git add src/Transformation/Execution tests/Transformation/Execution tests/Integration/OfficialInPlaceTransformation01Test.php tests/Integration/OfficialInPlaceTransformation02Test.php tests/Integration/InPlaceTransformationServiceTest.php
git commit -m "feat: enforce official SQL and Dolt apply context"
~~~

---

### Task 10: Remove the legacy API and publish conformance claims

**Files:**
- Delete: src/Transformation/Model
- Delete: src/Transformation/Validation
- Delete: src/Frontend/Spss/Binding/BoundCreateVariable.php
- Delete: src/Frontend/Spss/Binding/BoundDeleteVariable.php
- Delete: src/Frontend/Spss/Ast/StringStatement.php
- Delete: src/Frontend/Spss/Ast/DeleteVariablesStatement.php
- Delete: tests/Transformation/Canonical/TransformationPlanTest.php
- Modify: tests/Frontend/Spss/SpssCompilerTest.php
- Modify: tests/Core/CapabilityDeclarationTest.php
- Modify: src/Core/CapabilityDeclaration.php
- Modify: README.md
- Modify: docs/architecture.md
- Modify: docs/transformations.md
- Modify: docs/release-readiness.md
- Modify: CHANGELOG.md
- Modify: .github/workflows/ci.yml

**Interfaces:**
- Consumes: completed official model, frontend, audit, and executor.
- Produces: only the official public API and truthful machine-readable conformance claims.

- [ ] **Step 1: Write failing capability and legacy-absence tests**

~~~php
$contracts = $declaration['transformation_contracts'];
self::assertSame('official_conformant', $contracts['status']);
self::assertSame(
    ['openstatspec-transformation-plan-v0.1', 'openstatspec-transformation-plan-v0.2'],
    $contracts['official_plan_contracts'],
);
self::assertSame(
    ['openstatspec-spss-syntax-frontend-v0.2'],
    $contracts['official_frontend_contracts'],
);
self::assertSame(
    ['openstatspec-in-place-transformation-v0.1', 'openstatspec-in-place-transformation-v0.2'],
    $contracts['official_binding_contracts'],
);
self::assertArrayNotHasKey('legacy_plan_contracts', $contracts);
self::assertFalse(class_exists(OpenStatSpec\Transformation\Model\TransformationPlan::class));
~~~

- [ ] **Step 2: Run capability tests and verify red**

Run: vendor/bin/phpunit tests/Core/CapabilityDeclarationTest.php

Expected: FAIL because the declaration still reports legacy_contract_only.

- [ ] **Step 3: Migrate every remaining caller and delete the legacy tree**

Search:

~~~bash
rg -n "Transformation\\Model|Transformation\\Validation|openstatspec-transformation-plan-v1|CreateVariableOperation|DeleteVariableOperation|compileForDataset|compile\(" src tests README.md docs
~~~

Update remaining fixtures to SpssFrontendRequest, SpssCompilationResult, PlanCodec, and InPlaceApplyRequest. Delete legacy model/validator classes and the non-standard STRING/DELETE frontend path. The final search must return no production or documentation reference to the package-local contract or removed operations.

- [ ] **Step 4: Update capabilities and migration documentation**

Document the exact new flow:

~~~php
$compiled = (new SpssCompiler())->compile(SpssFrontendRequest::fromArray($request));
$apply = new InPlaceApplyRequest(
    plan: $compiled->plan,
    inputAlias: 'parent',
    datasetId: $datasetId,
    sourceHash: $compiled->sourceHash,
    actor: 'analyst@example.org',
    expectedBranch: $branch,
    expectedHead: $head,
);
$result = (new InPlaceTransformationExecutor(new Connection($pdo)))->execute($apply);
~~~

README and migration notes state that v0.6.0 removes openstatspec-transformation-plan-v1 plus STRING/DELETE transformation commands. Explain pre-provisioning on MySQL/MariaDB/Dolt and that Dolt commits remain caller-owned.

- [ ] **Step 5: Put the new integration gate in every service job**

Add OfficialInPlaceTransformation01Test and OfficialInPlaceTransformation02Test to PostgreSQL, MySQL, MariaDB, and Dolt PHPUnit filters without removing existing filters. The normal PHP 8.4/8.5 job continues running full composer check and therefore all plan/frontend fixture tests.

- [ ] **Step 6: Run every official conformance suite**

Run:

~~~bash
vendor/bin/phpunit tests/Transformation/Conformance
vendor/bin/phpunit tests/Frontend/Spss/Conformance
vendor/bin/phpunit tests/Integration/OfficialInPlaceTransformation01Test.php
vendor/bin/phpunit tests/Integration/OfficialInPlaceTransformation02Test.php
~~~

Expected: exact counts 4 Plan 0.1, 26 Plan 0.2, 44 Frontend 0.2, 6 In-Place 0.1, and 11 In-Place 0.2 cases for configured profiles.

- [ ] **Step 7: Run the complete local quality gate**

Run: composer check

Expected: composer validation, PHP syntax, style, PHPStan, and the full PHPUnit suite pass; skips are limited to unconfigured external database services.

- [ ] **Step 8: Review forbidden artifacts and API removal**

Run:

~~~bash
rg -n "derived_dataset|snapshot|rollback|staging|DOLT_COMMIT|openstatspec-transformation-plan-v1" src tests README.md docs
git diff --check
git status --short
~~~

Inspect every match and confirm there is no implementation path that creates a forbidden artifact or commits Dolt. Confirm the legacy contract has no remaining class, capability, example, or test.

- [ ] **Step 9: Commit**

~~~bash
git add -A
git commit -m "feat: claim official transformation and SPSS 0.2 conformance"
~~~

- [ ] **Step 10: Push and open the review PR**

Push codex/transformation-plan-spss-0.2, open a draft PR targeting main, and monitor every GitHub Actions job plus Codex review. Address only verified actionable feedback, rerun the affected local gates, and do not mark ready until all service matrices are green.
