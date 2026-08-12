# PHP Stable Specification Pin Design

## Goal

Bind the PHP reference adapter to the immutable OpenStatSpec specification
release `v0.3.0` at commit `cd8f198c68b849eb8ed018a894670a0904c2181d`.

## Design

The machine-readable capability declaration will expose the exact commit,
release identifier, and `stable` status through constants used by both the
top-level declaration and every SQL profile. All CI jobs that consume the
specification checkout will use the same exact commit. Focused tests will
assert the declaration and scan CI checkout refs so a later workflow edit
cannot silently move the conformance input.

The change does not claim official Transformation Plan or SPSS Frontend
conformance; those remain separately gated roadmap work. Documentation and the
unreleased changelog will record the pin and the next release readiness
requirements without creating a release tag in this PR.

## Verification

Run the focused pin tests first, then the full `composer check` with the
official `v0.3.0` specification checkout. Existing repository-wide PHP-CS-Fixer
line-ending drift is environment-related and must not be mixed into this
focused change.
