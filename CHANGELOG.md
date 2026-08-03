# Changelog

## [Unreleased]

### Changed

- Updated the installed and exact CI-tested `openstatspec/spss-sav` codec from
  3.0.2 to 3.0.3, and aligned the reported engine identity and codec
  documentation with the separately versioned OpenStatSpec package.

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

[Unreleased]: https://github.com/OpenStatSpec/php/compare/v0.4.0...HEAD
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
