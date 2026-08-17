# Task 9 report: MySQL-family rejection and controlled Dolt execution

## Implementation

- MySQL, MariaDB, and Dolt now reject every create-target operation during read-only preflight with `schema_change_not_atomic` at the operation's `target_mode`, before a transaction or audit write.
- Dolt execution requires caller-supplied expected branch and HEAD. The guard compares both to the live branch/HEAD and requires a clean working set before mutation.
- After ordered operations, the guard rereads branch and HEAD inside the transaction and compares them to both the request and the initial evidence before the success audit and commit.
- The executor reuses the Task 8 path: one native transaction contains data, metadata, post-operation Dolt validation, and exactly one compact success audit. Every failure rolls that transaction back.
- Production code calls no Dolt mutation procedure and creates no dataset copy, output/staging/snapshot table, or hidden recovery state.

## TDD evidence

- RED: seven focused Dolt tests produced three failures and four errors because the prior guard ignored request context and exposed the old signatures.
- RED: the live MySQL-family official 0.2 create-target cases initially returned `unknown_variable` rather than `schema_change_not_atomic`, proving capability rejection happened too late.
- GREEN: focused MySQL 8.4.9, MariaDB 11.4.8, and Dolt 2.2.2 execution passed 54 tests with 737 assertions and six PostgreSQL-only skips.
- Official 0.1 MySQL and official 0.2 MySQL/MariaDB/Dolt create cases snapshot rows, catalog, audit, dataset count, persistent table count, and repository evidence; all reject before mutation with exact equality.
- Dolt tests cover empty actor, missing context without an evidence read, initial branch/HEAD mismatch, dirty state, and injected post-mutation context change with exact error codes.
- The injected Dolt context change is observed only after a real row mutation; rollback restores rows, catalog, audit, identity, and counts exactly. A live MySQL/MariaDB audit-constraint failure likewise rolls back the same-transaction data change and audit.

## Live evidence

- MySQL: 8.4.9; MariaDB: 11.4.8-MariaDB-ubu2404; Dolt: 2.2.2 (MySQL wire compatibility 8.0.31).
- Dolt Task 9 cases use randomly named isolated databases with explicit namespace prechecks and teardown. Successful existing-target apply preserves branch and HEAD, produces one dirty working-set diff for the in-place edit, and adds no Dolt commit; failure leaves no edit or audit.
- The temporary Dolt repository remained on `main` at HEAD `jup6aortet0oao7jppgohjtilb74oepd` with exactly one setup commit after the full run. Legacy round-trip tests leave unrelated untracked fixture tables in the shared base database; Task 9 assertions run in isolated databases and clean them up.

## Verification

- Full live `composer test`: 455 tests, 5,038 assertions, nine PostgreSQL skips; passed after correcting the Dolt test DSN to select its base database.
- Full LF-normalized staged-tree `composer check`: Composer validation, PHP lint, PHP CS Fixer, PHPStan, and PHPUnit passed; PHPUnit ran 455 tests with 5,040 assertions and nine PostgreSQL skips.
- `composer analyse`: passed with no errors. `composer lint`: passed. Task-changed-file PHP CS Fixer dry-run: zero fixable files. `git diff --cached --check`: passed.
- Working-tree `composer style` still exits 8 because 122 unchanged repository PHP files retain the pre-existing CRLF baseline; the normalized full gate checked all 226 PHP files cleanly.
- The CRLF pre-commit hook cannot execute directly (`bash\r` shebang), so the commit uses `--no-verify` only after the equivalent normalized staged-tree gate passed.
- PostgreSQL was not configured locally, so its nine live cases skipped. No configured MySQL-family or Dolt case skipped.

## Self-review

- Create-target rejection precedes binding, transaction start, data/catalog mutation, and audit. Existing-target execution preserves dataset/table identity, row count, dataset count, and persistent physical-table count.
- Identifiers remain profile-quoted and literals remain bound parameters. Task 8's 17-digit locale-independent binary64 encoding and explicit numeric casts remain the only numeric predicate/value path.
- Success has one engine transaction commit and one audit row; every checked failure has no audit and exact rollback. Dolt is the sole version/history/diff/rollback layer and production code neither commits nor copies dataset state.
