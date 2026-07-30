# Changelog

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
