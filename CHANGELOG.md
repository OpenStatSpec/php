# Changelog

## [Unreleased]

### Changed

- Distinguished conservative MySQL 8.4.x/9.7.x, MariaDB
  11.4.x/11.8.x/12.3.x, and PostgreSQL 17.x/18.x runtime claims from exact CI
  evidence at MySQL 8.4.11/9.7.2, MariaDB 11.4.12/11.8.8/12.3.2, and
  PostgreSQL 17.10/18.4; live service tests now verify each normalized version.
- Kept Dolt independently pinned and service-tested at exact 2.2.2, clarified
  that PHP's SQLite core `>=3.24.0,<4.0.0` policy does not conflict with the
  Python-only optional workflow's `>=3.35.0,<4.0.0` policy, and documented
  Microsoft SQL Server as unsupported roadmap scope.
- Pinned active conformance fixtures and capabilities to untagged OpenStatSpec
  specification commit `e94ae8349d2b0dffe0c65e820b4b22b8c074b7b5` with a
  null release value.

[Unreleased]: https://github.com/OpenStatSpec/php/compare/v0.3.0...HEAD

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
