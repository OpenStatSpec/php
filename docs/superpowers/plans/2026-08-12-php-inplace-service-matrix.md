# PHP In-Place Transformation Service Matrix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an integration evidence gate proving the PHP adapter's in-place transformation behavior and target-creation boundary for every claimed SQL profile, using major/minor `.x` version ranges.

**Architecture:** Extend the existing PHPUnit integration conventions with one profile-aware test class. Reuse the production executor and normative catalog APIs; create isolated deterministic fixtures per test, and compare database/Dolt state before and after preflight rejection. No production behavior change is authorized unless a failing evidence test exposes a real defect.

**Tech Stack:** PHP 8.4/8.5, PHPUnit, PDO, existing OpenStatSpec normative catalog and transformation services, SQLite, PostgreSQL, MySQL, MariaDB, Dolt, GitHub Actions.

## Global Constraints

- PHP adapter only; Python remains a separate workstream.
- Test every configured profile: SQLite, PostgreSQL, MySQL, MariaDB, and Dolt.
- Missing local service configuration skips only that profile; CI service jobs must supply configuration and execute it.
- Database version expectations use major/minor `.x` ranges (`17.x`, `8.4.x`, `11.4.x`, `2.2.x`), never fixed patch versions.
- Use unique fixture names/UUIDs per test; do not drop shared schemas or hide observed state with cleanup.
- Do not create snapshots, staging/copy tables, rollback tables, Dolt commits, branch changes, resets, or other artifacts as part of evidence setup or assertions.
- Preserve the legacy transformation-contract disclaimer; do not claim an official Transformation Plan 1.0 profile.

---

## Task 1: Add the profile-aware fixture and existing-target evidence test

**Files:**
- Create: `tests/Integration/InPlaceTransformationServiceTest.php`
- Reference: `tests/Integration/ServerVersionEvidenceTest.php`
- Reference: `tests/Integration/OfficialSpssConformanceManifestTest.php`
- Reference: `src/Transformation/Execution/InPlaceTransformationExecutor.php`

- [ ] Inspect existing integration connection helpers, catalog setup, identifiers, and cleanup conventions.
- [ ] Write the provider/configuration test first and run the focused test to establish the expected failure.
- [ ] Add a profile provider covering SQLite plus the `OPENSTATSPEC_PG_*`, `OPENSTATSPEC_MYSQL_*`, `OPENSTATSPEC_MARIADB_*`, and `OPENSTATSPEC_DOLT_*` environment families, with expected-version checks as major/minor `.x` ranges.
- [ ] Add deterministic fixture setup using dataset UUID `018f47f2-8b6a-7c3d-9e1f-123456789abc`, source values `[1, 2, 3, 9, NULL]`, destination values `[-1, -1, -1, -1, -1]`, and profile-aware quoted identifiers.
- [ ] Add the existing-target test: recode to `[10, 20, 20, 9, 99]`, update the variable label and value labels, and assert result dataset UUID/plan hash, unchanged physical identity, unchanged dataset/table/variable identity, catalog metadata, and absence of snapshot/staging/copied/derived/rollback/parallel-history tables.
- [ ] Run the focused SQLite test and relevant static checks; commit as `test: add PHP in-place service evidence`.

## Task 2: Add the target-creation boundary evidence

**Files:**
- Modify: `tests/Integration/InPlaceTransformationServiceTest.php`
- Reference: `src/Transformation/Execution/InPlaceTransformationExecutor.php`
- Reference: `src/Transformation/Execution/*`

- [ ] Add a test for SQLite and PostgreSQL where a new numeric target is created in the same wide table; assert the new catalog variable, physical column, recoded values, and unchanged dataset/table identity.
- [ ] Add the equivalent MySQL, MariaDB, and Dolt test; capture rows, catalog rows, physical columns, dataset count, and table names before execution.
- [ ] Assert those profiles reject the plan during preflight with the documented capability exception and leave every captured value byte-for-byte equivalent.
- [ ] Run the focused test against SQLite and any configured service profiles; fix only defects demonstrated by the evidence test, adding regression coverage for each production fix.
- [ ] Commit as `test: prove in-place target capability boundary` (or a narrowly scoped `fix:` commit if production code is required).

## Task 3: Add Dolt repository-state assertions

**Files:**
- Modify: `tests/Integration/InPlaceTransformationServiceTest.php`
- Reference: `tests/Transformation/Execution/DoltGuardTest.php`
- Reference: `tests/Transformation/Execution/DoltHeadGuardTest.php`

- [ ] Record Dolt branch, HEAD, commit/history identity, and clean working-set status before the successful existing-target apply.
- [ ] Assert branch and HEAD remain unchanged, the expected inspectable working-set diff is present, and no commit or reset occurs.
- [ ] Record the same state before create-target rejection and assert branch, HEAD, history, status, rows, catalog, schema, and table names are unchanged afterward.
- [ ] Run the Dolt-focused test when `OPENSTATSPEC_DOLT_*` is configured and keep it skipped, not falsely green, when absent.
- [ ] Commit as `test: assert Dolt in-place repository evidence`.

## Task 4: Wire the evidence gate into CI

**Files:**
- Modify: `.github/workflows/ci.yml`

- [ ] Add `InPlaceTransformationServiceTest` to the focused PHPUnit filter for PostgreSQL, MySQL, MariaDB, and Dolt jobs without removing existing filters.
- [ ] Verify the normal PHP 8.4/8.5 job still runs the class through the complete Composer test suite with SQLite.
- [ ] Run YAML/configuration validation and inspect the diff; commit as `ci: run PHP in-place evidence across services`.

## Task 5: Document and verify

**Files:**
- Modify: `docs/transformations.md`
- Modify: `docs/release-readiness.md`
- Modify: `CHANGELOG.md`

- [ ] Document the service-matrix command, profile skip/CI execution rule, existing-target identity guarantee, and unsupported target-creation boundary.
- [ ] Keep wording explicit that this is adapter evidence and does not establish an official Transformation Plan 1.0 profile.
- [ ] Run focused PHPUnit, full `composer test`, `composer validate`, `composer lint`, `composer analyse`, and `git diff --check`.
- [ ] Review the complete diff for fixture isolation, no destructive cleanup, and coverage of every approved design requirement; commit as `docs: document PHP in-place evidence gate`.

## Final verification and handoff

- [ ] Confirm the worktree is clean and list implementation commits.
- [ ] Report which local profiles ran versus skipped due to missing configuration.
- [ ] If checks pass, request the local code-review loop before publishing a PR.
