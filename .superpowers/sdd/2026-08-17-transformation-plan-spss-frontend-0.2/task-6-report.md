# Task 6 report: bind and compile SPSS Frontend 0.2

## Status

Complete in the initial Task 6 commit plus one review-fix commit. Neither was pushed.

## Implementation

- Added strict `SpssFrontendRequest::fromArray()` validation for the exact Frontend 0.2 request shape, its ordered non-empty input schema, and every optional variable metadata field.
- Added source identity hashing that normalizes only CRLF and bare CR to LF before SHA-256 and remains available independently of parsing or binding success.
- Added an ASCII-case-insensitive evolving `SchemaState` that retains exact catalog spelling, resolves earlier created variables in later commands, and binds multi-variable `RECODE` sources and targets against pre-command state.
- Replaced the legacy binder/compiler boundary with typed bound nodes for recode, compute, conditional assignment, labels, formats, measurement levels, and execute.
- Compiled ordered bound statements one-for-one into the official Plan operation types and selected Plan 0.1 or 0.2 from each operation's minimum contract.
- Converted every decimal literal directly through `DecimalBinary64` into canonical `Binary64Value` bits, including exact rounding and positive-zero canonicalization, without a host-float parser intermediate.
- Added command, group, variable, expression, rule, value, result, and unsupported-command spans needed for stable binding diagnostics while preserving all source ordering.
- Added manifest-driven coverage for all 44 official Frontend 0.2 cases, exact source/plan hashes, exact canonical plans, referenced official Frontend 0.1 golden plans, plan contracts, stable failure codes, and non-null source spans.
- Added focused request, evolving-schema, simultaneous-recode, exact-binary64, diagnostic-code/span, invalid-empty-recode, and unsupported-legacy-command regressions.
- Review fix: existing reserved `__` COMPUTE and IF targets now fail in the binder with `reserved_target_name` at the exact target span, matching strict Plan validation; every successful official frontend plan is also decoded again through strict `PlanCodec` and compared canonically.

## TDD evidence

### RED

The official manifest test was added before the request/compiler implementation:

```text
vendor/bin/phpunit tests/Frontend/Spss/Conformance
44 errors: SpssFrontendRequest::fromArray() did not exist.
```

The focused compiler tests were then added before the new facade and binder:

```text
vendor/bin/phpunit tests/Frontend/Spss/SpssCompilerTest.php
25 errors: the strict request constructor and compile(request) boundary did not exist.
```

Further tests independently reproduced missing direct-constructor value-label validation, invalid ELSE-only RECODE output, loss of exact numeric source tokens, and zero-width unsupported-command spans before each corresponding fix.

The review regressions were added before the binder fix:

```text
vendor/bin/phpunit tests/Frontend/Spss/SpssCompilerTest.php --filter 'reserved existing'
FAILURES! Tests: 2, Assertions: 2, Failures: 2.
Both existing reserved targets unexpectedly compiled.
```

The root cause was that `validateTargetName()` ran only on the missing-target COMPUTE branch and never on IF; both existing-target paths could therefore emit a plan rejected by strict `PlanCodec`.

### GREEN

```text
vendor/bin/phpunit tests/Frontend/Spss/Conformance tests/Frontend/Spss/SpssCompilerTest.php
OK (76 tests, 301 assertions)
```

The 44 manifest cases are included in that count.

## Self-review

- **Request boundary:** exact root/schema/value object members are enforced; strings are valid UTF-8; ordered variables are non-empty; storage, typed values, formats, and measurement metadata are validated before parsing.
- **Source identity:** hashing is isolated from parsing and changes only line-ending bytes.
- **Resolution and ordering:** matching is ASCII-case-insensitive, emitted names preserve schema spelling, ambiguous names fail closed, new names retain source spelling, commands/groups/variables/rules/boolean operands remain ordered, and parallel RECODE targets are published only after the whole command binds.
- **Type and target semantics:** COMPUTE creates or replaces numeric targets; IF requires an existing numeric target; numeric-only expressions/formats, value-label types, ranges, result homogeneity, reserved names, fresh targets, and string limitations fail at the narrowest available source span.
- **Plan boundary:** emitted operations are official immutable Plan types; every successful official plan round-trips through strict `PlanCodec`; unchanged subsets stay Plan 0.1 and any 0.2 operation promotes the whole ordered plan to 0.2.
- **Failure behavior:** request, syntax, binding, and unsupported-command failures occur before mutation and carry the stable code/path/span contract.
- **Dolt/global constraints:** Task 6 changes only the pure frontend and tests. It adds no SQL mutation, dataset/table copy, derived dataset, staging/snapshot/rollback artifact, audit/version row, or Dolt operation; Dolt remains the sole version-history layer.
- **Scope:** the staged diff contains only Task 6 frontend source/tests and this report; unrelated changes were not included.

## Verification

```text
vendor/bin/phpunit tests/Transformation/Conformance tests/Frontend/Spss
OK (181 tests, 729 assertions)
```

```text
composer analyse
[OK] No errors
```

The CRLF-normalized staged-tree pre-commit hook ran the complete `composer check` gate successfully:

```text
composer validate --strict: valid
PHP lint: all source/test/tool files valid
php-cs-fixer: Found 0 of 213 files that can be fixed
PHPStan: [OK] No errors
PHPUnit: 408 tests, 2393 assertions, 30 skipped; exit 0
```

`git diff --cached --check` also passed.

The repository-wide working-tree `composer style` command was also run. It exits 8 because 133 of 213 repository files have pre-existing CRLF line endings; the normalized staged-tree hook runs the same style check against LF-normalized Git blobs and is the authoritative clean gate for the committed tree.

## Notes

- The Frontend 0.2 manifest's two `expected_plan_case_0_1` identifiers refer to cases in `spss-syntax-frontend-0.1.json`; they do not exist in `transformation-plan-0.1.json`. The conformance test therefore resolves those official references from their actual owning manifest rather than copying the golden plans.
- Verification used installed PHP 8.5.9 with Composer's declared PHP 8.4.1 platform floor.
