# PHP adapter release readiness

This page records the release contract for PHP adapter v0.7.1. It does not mean
that a package tag or Packagist publication has happened. Composer derives the
package version from the Git tag; `composer.json` has no `version` key.

## Specification pin and claims

The machine-readable capability declaration and every CI fixture checkout use
released OpenStatSpec specification `v0.5.0` at exact commit
`864e84479f554b8ee250ffed44c4dfb963750d4a`, with
`specification_status: released`.

Release v0.7.1 selects `database_io_policy: openstatspec-database-io-v1`.
SAV/ZSAV export is database-read-only, including failures, and no longer returns
`SpssExportResult::operationId` or writes operation/fidelity audit records.
Initialize or upgrade the catalogue with `SpssAdapter::migrateCatalog()` using a
write-capable deployment connection first; export never initializes or migrates
it and rejects unready catalogues with `catalog_migration_required`.
Exports use authoritative normative metadata and publish files only after a
successful temporary-file write.

Default Dolt writes support exactly 2.2.2 and 2.2.3 without external declaration
files. Unknown patches, including 2.2.4, fail before mutation. Read-only export
still verifies server identity but does not require a write-version claim.

Transformation claims remain Transformation Plan 0.1/0.2, SPSS Syntax Frontend
0.2, and In-Place Transformation 0.1/0.2. The specification's optional 0.3
contracts are not implemented or claimed. Existing target pre-provisioning and
caller-owned Dolt commit rules remain unchanged; see the
[transformation migration notes](transformations.md#v060-migration).

## Required gates

Before tagging v0.7.1:

1. Verify `git rev-parse HEAD` in the specification checkout equals
   `864e84479f554b8ee250ffed44c4dfb963750d4a`, and the published `v0.5.0` tag
   resolves to that commit. Run `composer check` with
   `OPENSTATSPEC_SPECIFICATION_DIR` pointing to that exact checkout. The tracked
   pre-commit hook repeats the gate on the LF-normalized staged archive.
2. Run every official transformation suite:
   ```bash
   vendor/bin/phpunit tests/Transformation/Conformance
   vendor/bin/phpunit tests/Frontend/Spss/Conformance
   vendor/bin/phpunit tests/Integration/OfficialInPlaceTransformation01Test.php
   vendor/bin/phpunit tests/Integration/OfficialInPlaceTransformation02Test.php
   ```
   Confirm all 4 Plan 0.1, 26 Plan 0.2, 44 Frontend 0.2, 6 In-Place 0.1,
   and 11 In-Place 0.2 manifest cases run for their configured profiles.
3. Confirm successful applies preserve dataset/table identity and counts and
   create no copied, output, staging, snapshot, rollback, or version state.
4. Confirm SQLite/PostgreSQL atomic numeric-target creation and preflight
   rejection on MySQL, MariaDB, and Dolt. Deployment must pre-provision and
   catalog targets on those three profiles.
5. Confirm live Dolt evidence proves stable branch/HEAD, a clean pre-apply
   working set, no adapter-created commit, and caller-owned commit policy.
   Run `DoltReadOnlyExportTest` on exact 2.2.2 and 2.2.3 with
   `OPENSTATSPEC_DOLT_READ_ONLY_ADMIN_DSN=mysql:host=127.0.0.1;port=3306;charset=utf8mb4`,
   `OPENSTATSPEC_DOLT_READ_ONLY_ADMIN_USER=root`, and
   `OPENSTATSPEC_DOLT_READ_ONLY_ADMIN_PASSWORD=root`. It creates and removes only
   its own isolated database/user and proves SELECT-only SAV/ZSAV export,
   failure safety, and unchanged working/staged roots and history.
6. Confirm GitHub Actions passes the full PHP 8.4/8.5 jobs plus every
   PostgreSQL, MySQL, MariaDB, and Dolt matrix entry. Each service filter must
   include both official in-place test classes; the Dolt filter must also
   include `DoltReadOnlyExportTest`.
7. Confirm README, changelog, and release notes agree on the exact specification
   pin, breaking export changes, initialization requirement, and Dolt defaults.
   Run `composer install --dry-run --no-dev` and
   `composer archive --format=zip --dir=/tmp/openstatspec-php-v071-package`;
   inspect the archive without publishing it. No separate build is required.
8. Publication is a separate maintainer action: create and verify annotated
   tag `v0.7.1` on the reviewed `main` commit, publish the GitHub release, and
   confirm Packagist installation. Do not infer publication from this checklist.
