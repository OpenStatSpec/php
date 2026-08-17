# PHP adapter release readiness

This page records the release contract for the next PHP adapter release. It
does not mean that a package tag or Packagist publication has happened.

## Specification pin

The adapter's machine-readable capability declaration and every CI conformance
checkout must use the stable OpenStatSpec specification release `v0.3.0` at
exact commit
`cd8f198c68b849eb8ed018a894670a0904c2181d`. The adapter reports
`specification_status=stable` and `specification_release=v0.3.0`.

This pin covers the source-faithful SPSS SAV/ZSAV relational contract. The PHP
adapter does not claim official Transformation Plan or SPSS Frontend profile
conformance; those remain separate implementation gates.

## Required gates

Before tagging a release:

1. Run `composer check` with `OPENSTATSPEC_SPECIFICATION_DIR` pointing to the
   exact `v0.3.0` checkout.
2. Run the focused service evidence command:
   ```bash
   OPENSTATSPEC_SPECIFICATION_DIR=/path/to/openstatspec-specification \
     vendor/bin/phpunit tests/Integration/InPlaceTransformationServiceTest.php
   ```
   Locally, SQLite executes and an unconfigured PostgreSQL, MySQL, MariaDB, or
   Dolt profile skips; CI must configure and execute every one of those
   service profiles.
3. Confirm that the existing-target service evidence preserves the existing
   dataset UUID, physical table, and variable identities without copied or
   rollback state. Confirm that implicit numeric target creation is restricted
   to SQLite and PostgreSQL, while MySQL, MariaDB, and Dolt reject it before
   mutation and require a pre-created, catalogued target.
4. Confirm GitHub Actions passes on PHP 8.4 and 8.5 plus the PostgreSQL,
   MySQL, MariaDB, and Dolt service matrices. This is adapter evidence under
   the legacy `openstatspec-transformation-plan-v1` contract, not an official
   OpenStatSpec Transformation Plan 1.0 profile claim.
5. Confirm the changelog and release notes describe the same specification
   release and commit.
6. Create an annotated `vX.Y.Z` tag on the reviewed `main` commit and verify
   that the tag points to that commit before publishing the GitHub release.
7. Confirm the resulting Composer package is installable from Packagist.
