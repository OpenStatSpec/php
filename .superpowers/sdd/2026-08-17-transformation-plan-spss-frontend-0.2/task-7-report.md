# Task 7 report: explicit transformation audit migration and apply request

## Implementation

- Added `InPlaceApplyRequest` with fail-fast alias, canonical UUID, lowercase SHA-256, non-empty actor, and paired Dolt-context validation.
- Added explicit schema-v4 `TransformationAuditMigrator` for SQLite, PostgreSQL, MySQL, MariaDB, and Dolt. SQLite rebuilds the one logical audit table transactionally; server profiles replace only the contract check before apply.
- Added success-only `TransformationAuditWriter`, which requires the caller's open apply transaction and stores canonical plan identity, source identity, actor, target identity, operation count, timestamps, and nullable Dolt evidence.
- Wired `SpssAdapter::migrateCatalog()` to run the audit migration after normative table creation and before marking schema version 4.
- Preserved official 0.1 audit rows while allowing both 0.1 and 0.2 binding contracts. No dataset/data table, snapshot, staging, rollback, or recovery relation was added.

## TDD evidence

- RED: `vendor/bin/phpunit tests/Transformation/Audit/TransformationAuditMigratorTest.php` reported 6 missing-class errors across 7 tests.
- GREEN: the final focused command `vendor/bin/phpunit tests/Transformation/Audit/TransformationAuditMigratorTest.php tests/Integration/ServerCatalogMigrationTest.php` passed 10 tests and 54 assertions; the only skip was the unconfigured server integration profile.
- Wiring mutation check: removing the adapter's explicit migrator call made `testAdapterMigrationRecordsFreshVersionsOneThroughFourExactlyOnce` fail because marker 4 was absent; restoring the call returned the suite to green.

## Verification

- `composer analyse`: `[OK] No errors`.
- Working-tree `composer style`: exits 8 because 133 pre-existing CRLF files are reported as fixable.
- CRLF-normalized staged-tree `composer check`: Composer validation, PHP lint, style (`0 of 217`), PHPStan, and PHPUnit all passed; PHPUnit ran 417 tests with 2,447 assertions and 30 environment skips.
- `git diff --cached --check`: passed.

## Self-review

- SQLite v0.1 migration preserves the row and logical table name, verifies foreign keys before commit, leaves no `_v04` residue, and rolls back to the old table on copy failure.
- PostgreSQL changes the old contract check in one native transaction; MySQL-family/Dolt DDL is explicit, idempotent, and never invoked by the audit writer or inside an apply transaction.
- The writer has no failure-row API and performs no migration, dataset copy, table copy, Dolt commit, branch operation, or recovery/version write.
- Fresh catalogs record versions 1 through 4 exactly once; v3 ownership recognition remains backward compatible, while v4 marker recognition requires the compact audit relation.

## Environment note

No server DSNs were configured locally, so PostgreSQL/MySQL/MariaDB/Dolt integration cases were the only focused skip; their migration test now exercises preservation of a pre-existing 0.1 row when those services are configured.
