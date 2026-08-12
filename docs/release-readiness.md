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
2. Confirm GitHub Actions passes on PHP 8.4 and 8.5 plus the PostgreSQL,
   MySQL, MariaDB, and Dolt service matrices.
3. Confirm the changelog and release notes describe the same specification
   release and commit.
4. Create an annotated `vX.Y.Z` tag on the reviewed `main` commit and verify
   that the tag points to that commit before publishing the GitHub release.
5. Confirm the resulting Composer package is installable from Packagist.
