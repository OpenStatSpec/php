# Task 7 report: explicit transformation audit migration and apply request

## Implementation

- Added `InPlaceApplyRequest` with fail-fast alias, canonical UUID, lowercase SHA-256, non-empty actor, and paired Dolt-context validation.
- Added explicit schema-v4 `TransformationAuditMigrator` for SQLite, PostgreSQL, MySQL, MariaDB, and Dolt. SQLite rebuilds the one logical audit table transactionally; server profiles replace only the contract check before apply.
- Added success-only `TransformationAuditWriter`, which requires the caller's open apply transaction and stores canonical plan identity, source identity, actor, target identity, operation count, timestamps, and nullable Dolt evidence.
- Wired `SpssAdapter::migrateCatalog()` to run the audit migration after normative table creation and before marking schema version 4.
- Preserved official 0.1 audit rows while allowing both 0.1 and 0.2 binding contracts. No dataset/data table, snapshot, staging, rollback, or recovery relation was added.
- MySQL-family hashes now use a MySQL 8.4/MariaDB/Dolt-compatible exact lowercase-hex check without `REGEXP BINARY`.
- MySQL-family contract upgrades add the v0.1+v0.2 check before dropping older checks. A retry can recover an already check-less table, and an interrupted add-before-drop attempt retains the old check.
- Schema version 4 can be marked or accepted as ready only when the contiguous 1..4 migration history and readable compact audit relation both exist. Normative migration recording is private and limited to versions 1..3; the audit migrator alone records version 4.

## TDD evidence

- RED: `vendor/bin/phpunit tests/Transformation/Audit/TransformationAuditMigratorTest.php` reported 6 missing-class errors across 7 tests.
- GREEN: the final focused command `vendor/bin/phpunit tests/Transformation/Audit/TransformationAuditMigratorTest.php tests/Integration/ServerCatalogMigrationTest.php` passed 10 tests and 54 assertions; the only skip was the unconfigured server integration profile.
- Wiring mutation check: removing the adapter's explicit migrator call made `testAdapterMigrationRecordsFreshVersionsOneThroughFourExactlyOnce` fail because marker 4 was absent; restoring the call returned the suite to green.
- Review RED: the two new ownership regressions both failed because `markCurrentVersion()` and readiness accepted an audit-less v4 claim.
- Review RED: the configured MySQL 8.4 integration test failed during fresh migration with SQLSTATE 3995 from `REGEXP BINARY`.
- Review GREEN: ownership regressions cover both the reported `identity=4 / migrations=1..3 / audit=absent` state and a forged marker 4 with no audit table. The server test also rejects uppercase source/plan hashes and simulates recovery from a missing MySQL-family contract check.
- Review follow-up RED/GREEN: a second MySQL run against the same migrated schema first failed because the legacy fixture hard-coded the pre-upgrade check name; introspecting the current check made two consecutive runs pass.

## Verification

- `composer analyse`: `[OK] No errors`.
- Working-tree `composer style`: exits 8 because 126 pre-existing CRLF files are reported as fixable.
- CRLF-normalized staged-tree `composer check`: Composer validation, PHP lint, style, PHPStan, and PHPUnit all passed; PHPUnit ran 420 tests with 2,454 assertions and 30 environment skips.
- Focused SQLite/unconfigured-server command passed 35 tests and 144 assertions with the one expected unconfigured-server skip.
- Live MySQL 8.4.9 integration passed twice consecutively against one schema, each run with 1 test and 13 assertions, including fresh/idempotent DDL, legacy upgrade, exact hash rejection, and missing-check retry recovery.
- Live MariaDB 11.6.2 DDL probe passed fresh migration, lowercase acceptance, uppercase source/plan rejection, missing-check retry recovery, and exactly one v4 marker. This locally available version is outside the adapter's claimed MariaDB families, so the probe invoked the migrator directly rather than misrepresenting it as a supported full adapter run.
- `git diff --cached --check`: passed.

## Self-review

- SQLite v0.1 migration preserves the row and logical table name, verifies foreign keys before commit, leaves no `_v04` residue, and rolls back to the old table on copy failure.
- PostgreSQL changes the old contract check in one native transaction; MySQL-family/Dolt DDL is explicit, add-before-drop, retryable, idempotent, and never invoked by the audit writer or inside an apply transaction.
- The writer has no failure-row API and performs no migration, dataset copy, table copy, Dolt commit, branch operation, or recovery/version write.
- Fresh catalogs record versions 1 through 4 exactly once; v3 ownership recognition remains backward compatible, while v4 marker recognition requires the compact audit relation.

## Environment note

No PostgreSQL or Dolt service was available locally. Their configured-server integration branches remain covered by the same portable migration test; PostgreSQL's check replacement remains transactionally atomic, while Dolt follows the live-probed MySQL-family DDL path.
