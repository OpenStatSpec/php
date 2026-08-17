# Task 5 report: PHP in-place service-matrix documentation

## Status

Documented the PHP adapter service-matrix gate in the requested three
documentation files. The wording preserves the package-local legacy
`openstatspec-transformation-plan-v1` disclaimer and explicitly makes no
official OpenStatSpec Transformation Plan 1.0 profile claim.

## Documentation coverage

- Provides the focused command:
  `OPENSTATSPEC_SPECIFICATION_DIR=/path/to/openstatspec-specification vendor/bin/phpunit tests/Integration/InPlaceTransformationServiceTest.php`.
- States that SQLite runs locally; an unavailable local PostgreSQL, MySQL,
  MariaDB, or Dolt profile skips, while CI supplies configuration and must run
  every service profile.
- States that existing-target evidence preserves the dataset UUID, physical
  table, and existing variable identities without copied, staging, snapshot,
  rollback, or parallel-history tables.
- States the create-target boundary: only SQLite/PostgreSQL evidence implicit
  numeric targets; MySQL/MariaDB/Dolt reject implicit creation before mutation
  and require a pre-created, catalogued target.

## Verification

- Focused service matrix: pass — 24 tests, 91 assertions, 16 expected skips.
- Full `composer test` with
  `OPENSTATSPEC_SPECIFICATION_DIR=/home/tonis/PhpstormProjects/survey-db/specification`:
  pass — 246 tests, 1,725 assertions, 30 expected skips.
- `composer validate --strict`, `composer lint`, and `composer analyse`: pass.
- `git diff --check` for the documentation patch and complete matrix branch:
  pass.
- `composer style`: non-zero baseline issue. PHP CS Fixer reports 145 of 146
  PHP files as line-ending-only CRLF changes, including untouched files; no
  formatter change was made.

## Local-profile disclosure

- Ran: SQLite.
- Skipped: PostgreSQL (the local PHP runtime lacks `pdo_pgsql`), MySQL,
  MariaDB, and Dolt (their `OPENSTATSPEC_*_DSN` values are not configured).

## Branch-diff review

Reviewed `d83c20e..HEAD`. Fixtures use deterministic per-test identities and
the Dolt path creates/drops only generated, random-suffixed isolated databases;
there is no shared-schema drop, truncate, reset, or shared-state cleanup.
The existing-target identity and create-target boundary assertions cover the
approved requirements, and CI invokes the evidence class for every service.

## Concern for local code review

The test fixture implementation creates a baseline Dolt commit in its isolated
test database (`commitDoltFixture`), although the plan's global constraints
say not to create Dolt commits as evidence setup. This does not affect a shared
Dolt repository, but the local code-review loop should decide whether the
isolated baseline commit is an acceptable exception before a PR is published.
