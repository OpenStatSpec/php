# Task 8 report: atomic official-plan execution on SQLite and PostgreSQL

## Implementation

- Replaced the legacy executor entry point with `execute(InPlaceApplyRequest)` and split mutation-free binding from ordered SQL execution.
- Added immutable bound plan/operation/schema objects. Preflight revalidates the official canonical plan, catalog ownership and audit readiness, resolves the exact dataset/table and variable bindings, verifies physical columns, and simulates the complete evolving schema before starting a transaction.
- Added deterministic, parameterized predicate and operand SQL. All physical identifiers are profile-quoted; literals, labels, and values are bound parameters. Boolean operands retain source order and SQL three-valued truth.
- Implemented ordered in-place `recode`, `assign`, `conditional_assign`, variable-label, value-label, format, measurement-level, and `execute` behavior. First-match recodes, inclusive ranges, exact typed values, numeric system missing, unmatched results, and sequential dependencies follow the official 0.1/0.2 plans.
- SQLite and PostgreSQL create nullable numeric targets with one same-table `ALTER TABLE`, one minimal catalog variable row, and the data update inside the public transaction. New variables receive a fresh UUID and next ordinal with no implicit label, value-label, missing, format, display, role, or measurement metadata.
- Success audit insertion shares the data/catalog/schema transaction and returns a non-null audit ID. Any apply failure rolls back; the executor no longer starts or writes `OperationJournal` records.
- Migrated executor integration callers to official plans/apply requests, including the PostgreSQL physical-column-limit regression.

## TDD evidence

- RED: the initial Task 8 focused suite produced 16 errors because the legacy executor rejected `InPlaceApplyRequest` at its old plan-only signature.
- GREEN: the final focused command passed 44 tests and 387 assertions; 18 configured-service cases skipped.
- SQLite success tests prove unchanged dataset count, persistent data-table count, dataset/table identity, table set, case order/count, and existing variable identity while applying exact row and metadata changes.
- SQLite injected-audit failure proves transactional rollback of the added physical column, catalog variable, row updates, and audit row.
- Preflight tests cover unknown/type/target/format/audit errors, a later invalid operation preventing an earlier valid mutation, and rejection of caller-owned transactions.
- SQL tests cover malicious catalog identifiers and bound label/string payloads, including exact string recoding over a case-insensitive SQLite column.
- Official 0.1/0.2 tests cover ordered first-match recode, inclusive ranges, numeric system missing, copy/unmatched behavior, sequential assign and conditional assign, SQL NULL truth, complete value-label replacement, exact format/measurement updates, and preservation of unrelated missing/display metadata.
- PostgreSQL success and injected-audit rollback probes are environment-gated and use the same official atomic-create case. Both initialize a fresh catalog before namespace checks and verify no additional persistent relation.

## Verification

- Focused executor regressions: 44 tests, 387 assertions, 18 skips; passed.
- `composer analyse`: passed with no errors.
- `composer lint`: passed.
- Full CRLF-normalized staged-tree `composer check`: Composer validation, PHP lint, style, PHPStan, and PHPUnit passed; PHPUnit ran 419 tests with 2,611 assertions and 32 environment skips.
- `git diff --cached --check`: passed.
- Working-tree `composer style`: exits 8 because 125 repository files retain pre-existing CRLF line endings. The LF-normalized staged-tree style gate found zero fixable files across all 225 checked files.

## Self-review

- Successful applies retain the one dataset row and one physical wide table; create-target changes only that table's schema and its normative variable catalog.
- Production executor code creates no derived/output/staging/snapshot/rollback/history/recovery relation, stores no copied dataset state, performs no compensating cleanup, and invokes no Dolt mutation procedure.
- Preflight is read-only and completes before `beginTransaction`; ordered operation SQL, Dolt post-context observation where applicable, and exactly one success audit share the native transaction.
- Metadata writes target only the named normative fields. Value-label replacement is copy-on-write for a shared label set and leaves missing values and unrelated metadata untouched.
- PostgreSQL physical-slot accounting includes dropped column slots through `pg_attribute`, so target creation fails before mutation at the server's wide-table limit.

## Environment note

`OPENSTATSPEC_PG_DSN`, `OPENSTATSPEC_PG_USER`, and `OPENSTATSPEC_PG_PASSWORD` were not configured locally, so the two new live PostgreSQL success/rollback probes and the existing PostgreSQL integrations skipped. SQLite supplied the local native-DDL transaction evidence; the PostgreSQL paths remain in the normalized full suite and run automatically when the documented environment is configured.
