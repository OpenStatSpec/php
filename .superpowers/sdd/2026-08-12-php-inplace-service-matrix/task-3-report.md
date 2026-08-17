# Task 3 report: Dolt repository-state assertions

## Status

Implemented the Task 3 test-only evidence in `tests/Integration/InPlaceTransformationServiceTest.php`. No production code, Dolt branch, reset, or commit behavior was changed.

## Changes

- Added a read-only Dolt repository probe for active branch, `HEAD`, reachable commit hashes, full `dolt_status` rows, and `WORKING` rows from `dolt_diff`.
- The existing-target success case now proves the fixture baseline is clean, branch/HEAD/history remain unchanged, and exactly the expected five tables remain as unstaged, data-only working-set changes.
- The create-target rejection case now snapshots and compares Dolt repository state alongside rows, full fixture catalog, physical columns, counts, and table names.
- Added deterministic normalization coverage for PDO string/integer boolean values so the probe is exercised without a live Dolt service.
- Preserved Task 1 isolation: admin credentials are used only for database create/drop; target credentials run fixtures and executor; fixture setup creates one approved baseline commit; teardown drops the isolated database after assertions.

## TDD evidence

- RED: the normalization test first errored because the helper did not exist; after adding only a placeholder, it failed with the intended expected-array versus empty-array mismatch.
- GREEN: the minimal normalization implementation passed: 1 test, 1 assertion.
- Focused class/executor/guard run: 49 tests, 240 assertions, 16 skipped, 0 failures.

## Dolt execution disclosure

`OPENSTATSPEC_DOLT_DSN`, target user/password, and admin user/password are absent locally. The Dolt-backed provider cases therefore remain explicit PHPUnit skips; no live Dolt result is reported as green. The deterministic normalization test and existing executor guard tests ran locally.

## Verification

- Focused PHPUnit: pass (49 tests, 240 assertions, 16 external-service skips).
- Full PHPUnit: pass (246 tests, 1,725 assertions, 30 external-service skips).
- PHP lint: pass.
- PHPStan: pass with no errors.
- Targeted PHP CS Fixer dry run for the changed PHP file: pass.
- Repository-wide PHP CS Fixer dry run: non-zero because 145 untouched PHP files are checked out with CRLF line endings; its output proposes line-ending-only changes. This is the same pre-existing baseline issue recorded in the Task 2 report.
- `git diff --check`: pass.

## Concerns

- Live Dolt 2.2.x must execute the skipped provider cases in configured CI to validate the exact system-table rows against the service.
- The success assertion intentionally expects five data-only dirty tables: the physical wide table, `variable`, `value_label_set`, `value_label`, and `variable_value_label_set`.

## Review fix

- Read-only review against Dolt 2.2.3 found that `dolt_log.commit_order` must be sorted descending to place `HEAD` first. The probe query now uses `ORDER BY commit_order DESC, commit_hash`; this preserves stable history comparison and makes the pre-apply `HEAD` assertion valid.
- The reviewer reran the two affected Dolt provider cases after the ordering fix: both passed (`8 tests, 85 assertions, 5 unrelated provider skips`).
- That live run exposed uppercase `COLUMN_NAME` metadata from Dolt. Added deterministic key-case normalization coverage and applied it to MySQL-family column metadata so schema assertions read real column names without warnings; the final live rerun passed both cases with zero warnings.
