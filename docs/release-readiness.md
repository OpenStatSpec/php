# PHP adapter release readiness

This page records the release contract for PHP adapter v0.6.0. It does not mean
that a package tag or Packagist publication has happened.

## Specification pin and claims

The machine-readable capability declaration and every CI fixture checkout use
stable OpenStatSpec specification release `v0.3.0` at exact commit
`cd8f198c68b849eb8ed018a894670a0904c2181d`.

Release v0.6.0 claims conformance with Transformation Plan 0.1/0.2, SPSS Syntax
Frontend 0.2, and In-Place Transformation 0.1/0.2. It removes the package-local
plan API and the non-standard SPSS schema-edit commands described in the
[transformation migration notes](transformations.md#v060-migration).

## Required gates

Before tagging v0.6.0:

1. Run `composer check` with `OPENSTATSPEC_SPECIFICATION_DIR` pointing to the
   exact pinned checkout.
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
6. Confirm GitHub Actions passes the full PHP 8.4/8.5 jobs plus every
   PostgreSQL, MySQL, MariaDB, and Dolt matrix entry. Each service filter must
   include both official in-place test classes.
7. Confirm the changelog and release notes describe the same specification
   release, commit, breaking removals, and pre-provisioning rules.
8. Create annotated tag `v0.6.0` on the reviewed `main` commit, verify the tag,
   publish the GitHub release, and confirm Packagist installation.
