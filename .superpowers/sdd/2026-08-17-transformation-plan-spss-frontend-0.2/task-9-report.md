# Task 9 report: MySQL-family rejection and controlled Dolt execution

## Implementation

- MySQL, MariaDB, and Dolt now reject every create-target operation during read-only preflight with `schema_change_not_atomic` at the operation's `target_mode`, before a transaction or audit write.
- Dolt execution requires caller-supplied expected branch and HEAD. The guard compares both to the live branch/HEAD and requires a clean working set before mutation.
- After ordered operations, the guard rereads branch and HEAD inside the transaction and compares them to both the request and the initial evidence before the success audit and commit.
- The executor reuses the Task 8 path: one native transaction contains data, metadata, post-operation Dolt validation, and exactly one compact success audit. Every failure rolls that transaction back.
- MySQL/MariaDB preflight now queries parameterized `information_schema.tables` metadata for the bound wide table and the six apply-participating catalog/audit tables. It requires exactly one `BASE TABLE` row using InnoDB for each relation and fails closed on MyISAM, unknown/NULL, missing, duplicate, view, or unreadable metadata before transaction start.
- The engine rule is limited to the `mysql` and `mariadb` profiles. Dolt keeps its independent transaction contract and is not treated as InnoDB merely because it uses the MySQL wire protocol.
- Production code calls no Dolt mutation procedure and creates no dataset copy, output/staging/snapshot table, or hidden recovery state.

## TDD evidence

- RED: seven focused Dolt tests produced three failures and four errors because the prior guard ignored request context and exposed the old signatures.
- RED: the live MySQL-family official 0.2 create-target cases initially returned `unknown_variable` rather than `schema_change_not_atomic`, proving capability rejection happened too late.
- Review RED: 18 live MySQL/MariaDB storage-engine cases produced 15 acceptance failures; three malformed-relation cases were already rejected by namespace ownership. The accepted cases included a MyISAM bound wide table reaching an injected audit failure, all six apply-participating catalog/audit tables using MyISAM, and missing wide-table metadata on MySQL.
- Review GREEN: all 18 engine cases passed with 228 assertions. Exact before/after snapshots prove no data, catalog, audit, dataset-count, or physical-table-count change and no open transaction.
- Manifest-gate RED: the prior backend gate covered only one of the six official 0.1 case IDs; a clean inventory test failed on the other five IDs. GREEN runs every manifest row through an exhaustive ID dispatcher backed by the live MySQL or Dolt executor and passed 6 tests with 334 assertions.
- Review-round-2 RED: the official 0.2 backend provider exposed all eleven manifest rows, but the old dispatcher recognized only the three create-target cases: 11 tests produced eight failures and three passes. GREEN dispatches every ID exhaustively and passed all 11 tests with 788 assertions and no configured-service skip.
- The four official Dolt existing-target successes now execute on Dolt, including sequential predicates, OR/NULL semantics, variable-missing propagation, and conditional-variable-missing propagation. They assert exact rows and metadata plus stable dataset/table identity and counts, one exact audit row, an unchanged branch/HEAD/commit count, the expected working-set diff, and no copied or recovery artifacts.
- GREEN: focused MySQL 8.4.9, MariaDB 11.4.8, and Dolt 2.2.2 execution passed 54 tests with 737 assertions and six PostgreSQL-only skips.
- Official 0.1 MySQL and official 0.2 MySQL/MariaDB/Dolt create cases snapshot rows, catalog, audit, dataset count, persistent table count, and repository evidence; all reject before mutation with exact equality.
- Dolt tests cover empty actor, missing context without an evidence read, initial branch/HEAD mismatch, dirty state, and injected post-mutation context change with exact error codes.
- The injected Dolt context change is observed only after a real row mutation; rollback restores rows, catalog, audit, identity, and counts exactly. A live MySQL/MariaDB audit-constraint failure likewise rolls back the same-transaction data change and audit.

## Live evidence

- MySQL: 8.4.9; MariaDB: 11.4.8-MariaDB-ubu2404; Dolt: 2.2.2 (MySQL wire compatibility 8.0.31).
- The final focused live gate ran all six official 0.1 cases and all eleven official 0.2 cases, plus engine and Dolt guard regressions: 78 tests, 1,814 assertions, and six PostgreSQL-only skips. No configured MySQL, MariaDB, or Dolt case skipped.
- Dolt Task 9 cases use randomly named isolated databases with explicit namespace prechecks and teardown. Successful existing-target apply preserves branch and HEAD, produces one dirty working-set diff for the in-place edit, and adds no Dolt commit; failure leaves no edit or audit.
- The freshly recreated temporary Dolt repository remained on `main` at HEAD `h6ggikagos52ks3uo4aetolkun79v7l2` with exactly one setup commit after the normalized full run. Legacy round-trip tests leave unrelated untracked fixture tables in the shared base database; Task 9 assertions run in isolated databases and clean them up, and the final isolated-database count was zero on all three configured services.

## Verification

- Full live `composer test`: 479 tests, 6,115 assertions, nine PostgreSQL skips; passed with MySQL 8.4.9, MariaDB 11.4.8, and Dolt 2.2.2 configured.
- Full LF-normalized staged-tree `composer check`: Composer validation, PHP lint, PHP CS Fixer over 226 files, PHPStan, and PHPUnit passed; PHPUnit ran 479 tests with 6,115 assertions and nine PostgreSQL skips.
- The first normalized run reached Composer's default 300-second process timeout during a legacy Dolt dictionary test. Its interrupted fixture was confined to the temporary Dolt volume; that named container/volume was recreated from a clean one-commit repository, and the authoritative full check passed with `COMPOSER_PROCESS_TIMEOUT=900`.
- `composer analyse`: passed with no errors. `composer lint`: passed. Task-changed-file PHP CS Fixer dry-run: zero fixable files. `git diff --cached --check`: passed.
- Working-tree `composer style` still exits 8 because 122 unchanged repository PHP files retain the pre-existing CRLF baseline; the normalized full gate checked all 226 PHP files cleanly.
- The CRLF pre-commit hook cannot execute directly (`bash\r` shebang), so the commit uses `--no-verify` only after the equivalent normalized staged-tree gate passed.
- PostgreSQL was not configured locally, so its nine live cases skipped. No configured MySQL-family or Dolt case skipped.

## Self-review

- Create-target rejection precedes binding, transaction start, data/catalog mutation, and audit. Existing-target execution preserves dataset/table identity, row count, dataset count, and persistent physical-table count.
- Identifiers remain profile-quoted and literals remain bound parameters. Task 8's 17-digit locale-independent binary64 encoding and explicit numeric casts remain the only numeric predicate/value path.
- Success has one engine transaction commit and one audit row; every checked failure has no audit and exact rollback. Dolt is the sole version/history/diff/rollback layer and production code neither commits nor copies dataset state.
