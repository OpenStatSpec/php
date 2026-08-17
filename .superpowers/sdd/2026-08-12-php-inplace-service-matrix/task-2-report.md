# Task 2 Report

## Scope

Implemented Task 2 only in `tests/Integration/InPlaceTransformationServiceTest.php`, extending the existing Task 1 service-matrix fixture coverage without changing CI, docs, or production code.

## What Changed

- Added `createTargetCapableServices()` for SQLite and PostgreSQL success evidence.
- Added `createTargetRejectedServices()` for MySQL, MariaDB, and Dolt preflight rejection evidence.
- Added `testCreateTargetTransformationCreatesNumericTargetInPlace()` to prove:
  - the dataset row and physical table identity stay unchanged,
  - a new numeric target catalog variable is created,
  - a new physical column is appended in-place,
  - recoded values land in the new column,
  - metadata operations against the implicit target succeed.
- Added `testCreateTargetTransformationRejectsNonAtomicProfilesWithoutChangingState()` to prove:
  - MySQL-family profiles reject the plan during preflight with `TargetCapabilityExceeded`,
  - data rows, dataset row, variable catalog rows, column list, table list, and dataset/variable counts remain unchanged.
- Added `createTargetPlan()` and small helper extensions for reusable value-label and row snapshots.

## TDD Notes

- Wrote the new evidence tests first.
- Ran the focused test target immediately after adding them.
- Result: the new tests passed on the currently reachable local backend set, so no production defect was exposed and no source changes were necessary.

## Verification

Commands run:

```bash
vendor/bin/phpunit --filter InPlaceTransformationServiceTest
vendor/bin/phpunit --filter "InPlaceTransformationServiceTest|InPlaceTransformationExecutorTest"
php -l tests/Integration/InPlaceTransformationServiceTest.php
git diff --check
```

Observed results:

- `vendor/bin/phpunit --filter InPlaceTransformationServiceTest`
  - `OK, but some tests were skipped!`
  - `Tests: 20, Assertions: 57, Skipped: 16.`
- `vendor/bin/phpunit --filter "InPlaceTransformationServiceTest|InPlaceTransformationExecutorTest"`
  - `OK, but some tests were skipped!`
  - `Tests: 41, Assertions: 196, Skipped: 16.`
- `php -l tests/Integration/InPlaceTransformationServiceTest.php`
  - `No syntax errors detected`
- `git diff --check`
  - no whitespace or patch-shape errors

## Self-Review

- Verified the change stays inside the required integration test class.
- Verified the rejection test asserts the documented preflight exception message from the in-place executor capability guard.
- Verified the success test proves unchanged dataset/table identity while checking the newly created catalog variable, physical column, and recoded values.
- Verified no CI or documentation files were modified.

## Concerns

- This shell session has no `OPENSTATSPEC_*` DSN environment variables configured, so the fresh local execution covered SQLite plus the related SQLite executor unit path; PostgreSQL/MySQL/MariaDB/Dolt branches remain encoded in the integration matrix tests for configured environments and CI.

## Fix Round 1

- Extended the rejected create-target evidence to snapshot and re-assert `value_label_set`, `value_label`, and `variable_value_label_set` rows for the fixture dataset before and after the expected `UnsupportedOperation`.
- This closes the review gap where the rejected plan included `SetVariableLabelOperation` and `SetValueLabelsOperation` for `CreatedTarget`, but the unchanged-state proof did not include the value-label catalog tables those operations would touch.

Commands run:

```bash
vendor/bin/phpunit --filter "InPlaceTransformationServiceTest|InPlaceTransformationExecutorTest"
php -l tests/Integration/InPlaceTransformationServiceTest.php
git diff --check
```

Observed results:

- `vendor/bin/phpunit --filter "InPlaceTransformationServiceTest|InPlaceTransformationExecutorTest"`
  - `OK, but some tests were skipped!`
  - `Tests: 42, Assertions: 212, Skipped: 16.`
- `php -l tests/Integration/InPlaceTransformationServiceTest.php`
  - `No syntax errors detected`
- `git diff --check`
  - no whitespace or patch-shape errors

Round-specific concern:

- The MySQL/MariaDB/Dolt rejection branches are still skipped in this shell because their `OPENSTATSPEC_*` DSNs are not configured locally, so the new preflight-rejection value-label assertions remain encoded for configured environments and CI rather than executed here.

## Fix Round 1 Follow-up

- Replaced the rejected-plan snapshots with a full raw catalog snapshot: `SELECT *` for the dataset and variables, plus complete value-label catalog rows.
- Replaced normalized rejected-plan row comparisons with raw PDO rows, retaining the driver-returned value representation and PHP types.
- Added a profile-aware physical SQL type assertion for `createdtarget`; SQLite verifies `REAL` and PostgreSQL verifies `DOUBLE PRECISION` through its catalog.
- Added explicit physical-table-count assertions for both creation and rejection paths.

## TDD Evidence

- Temporarily mutated numeric target creation to use the text type. The SQLite create-target test failed as intended with expected `REAL`, actual `TEXT`; the mutation was reverted before verification.

## Verification

```bash
vendor/bin/phpunit --filter "InPlaceTransformationServiceTest|InPlaceTransformationExecutorTest"
php -l tests/Integration/InPlaceTransformationServiceTest.php
composer lint
composer analyse
composer style
git diff --check
```

- Focused PHPUnit: `Tests: 43, Assertions: 228, Skipped: 16.`
- PHP lint and PHPStan passed.
- `git diff --check` passed.
- `composer style` remains non-zero because the repository baseline has CRLF line endings in all 146 checked files; its dry-run output proposes only line-ending changes, including untouched files.

## Concern

- PostgreSQL/MySQL/MariaDB/Dolt DSNs are not configured locally. Their matrix cases remain encoded but skipped in this shell.
