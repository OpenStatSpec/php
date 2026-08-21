# Changelog

## [0.6.0] - 2026-08-21

### Added

- Added official OpenStatSpec Transformation Plan 0.1/0.2, SPSS Syntax
  Frontend 0.2, and In-Place Transformation 0.1/0.2 conformance, including
  strict canonical codecs, source hashes, compact apply audit, and service
  matrix coverage.

### Changed

- Pinned the release to stable OpenStatSpec specification `v0.3.0` at immutable
  commit `cd8f198c68b849eb8ed018a894670a0904c2181d`.
- Transformations now compile `SpssFrontendRequest` into an alias-based
  `SpssCompilationResult` and apply it through `InPlaceApplyRequest` with an
  explicit actor and optional controlled Dolt context.
- SQLite and PostgreSQL may create numeric targets atomically. MySQL, MariaDB,
  and Dolt require targets to be pre-provisioned and catalogued. Dolt commits
  remain caller-owned.
- Added a new runtime dependency on `brick/math ^0.19` for exact
  decimal-to-binary64 conversion in the SPSS frontend. The dependency is
  pinned in `composer.lock`; do not regenerate `composer.lock` without
  re-running the full test matrix because PHP-native BCMath lacks the
  bit-length and power-of-two helpers required by round-to-even rounding.

### Removed

- Version 0.6.0 removes the package-local
  `openstatspec-transformation-plan-v1` API without a compatibility adapter.
- Removed the non-standard SPSS `STRING` and `DELETE VARIABLES`
  transformation commands.

## [0.5.0] - 2026-08-17

### Added

- Added numeric and string variable creation plus variable deletion to the
  package-local transformation API and SPSS syntax frontend.

### Changed

- Pinned the active conformance fixtures and machine-readable capability
  declarations to stable OpenStatSpec specification `v0.3.0` at immutable
  commit `cd8f198c68b849eb8ed018a894670a0904c2181d`.

- Updated the installed and exact CI-tested `openstatspec/spss-sav` codec from
  3.0.2 to 3.0.3, and aligned the reported engine identity and codec
  documentation with the separately versioned OpenStatSpec package.
- Made the transformation capability boundary explicit: the current PHP API uses the package-local legacy `openstatspec-transformation-plan-v1` contract and does not yet claim official Transformation Plan or SPSS Frontend profile 0.1/0.2 conformance.

- Added the PHP in-place transformation service-matrix evidence gate. SQLite
  runs locally; locally unconfigured PostgreSQL, MySQL, MariaDB, and Dolt
  profiles skip, while CI configures and executes every service profile. The
  gate proves existing-target dataset, physical-table, and variable identity;
  it restricts implicit numeric target creation to SQLite/PostgreSQL and
  requires MySQL/MariaDB/Dolt targets to be created and catalogued first.
  This remains adapter evidence under the legacy contract, not an official
  OpenStatSpec Transformation Plan 1.0 profile claim.

## [0.4.0] - 2026-07-31

### Added

- Added a source-neutral canonical transformation plan, validation, provenance,
  and in-place execution layer for recodes, variable labels, and value labels.
- Added an SPSS syntax frontend and documented extension points for future
  statistical-language frontends, with explicit SAS and Stata placeholders.

### Changed

- Transformations now mutate the existing logical dataset and physical wide
  table without creating copied datasets, persistent staging tables, or an
  OpenStatSpec-managed undo/version history; Dolt identity can be recorded for
  audit without making Dolt mandatory for other supported connections.

- Distinguished conservative MySQL 8.4.x/9.7.x, MariaDB
  11.4.x/11.8.x/12.3.x, and PostgreSQL 17.x/18.x runtime claims from exact CI
  evidence at MySQL 8.4.11/9.7.2, MariaDB 11.4.12/11.8.8/12.3.2, and
  PostgreSQL 17.10/18.4; live service tests now verify each normalized version.
- Expanded Dolt's independent runtime claim to canonical stable
  `>=2.2.2,<2.3.0` releases while retaining immutable live 2.2.2 and 2.2.3
  service evidence and all boundary, cleanup, and conformance gates; clarified
  that PHP's SQLite core `>=3.24.0,<4.0.0` policy does not conflict with the
  Python-only optional workflow's `>=3.35.0,<4.0.0` policy, and documented
  Microsoft SQL Server as unsupported roadmap scope.
- Pinned active conformance fixtures and capabilities to released OpenStatSpec
  specification v0.1.0 at commit `d287c2cde9ade71f04e27dd012caec876901aed5`.

[Unreleased]: https://github.com/OpenStatSpec/php/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/OpenStatSpec/php/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/OpenStatSpec/php/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/OpenStatSpec/php/compare/v0.3.0...v0.4.0

## [0.3.0] - 2026-07-30

### Added

- Added an exact, fail-closed Dolt 2.2.2 profile with machine-readable
  capabilities and pinned live SAV/ZSAV conformance coverage.
- Added guarded SPSS imports with caller-verified SHA-256 source hashes and
  descriptor path isolation.

### Changed

- Import preflight now validates database limits, non-finite numeric values,
  and V3 dictionary and set metadata before mutating the target database.
- Compensating cleanup now tracks exact import ownership and preserves
  unrelated same-name catalogue and fidelity data after failed imports.
- Updated catalogue migrations, database-version claims, and the pinned
  OpenStatSpec specification commit used by capability declarations.

[0.3.0]: https://github.com/OpenStatSpec/php/compare/v0.2.0...v0.3.0
