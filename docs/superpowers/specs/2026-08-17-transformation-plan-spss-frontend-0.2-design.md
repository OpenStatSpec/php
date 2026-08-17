# Transformation Plan and SPSS Frontend 0.2 Design

**Date:** 2026-08-17  
**Status:** Approved for implementation planning

## Goal

Replace the package-local `openstatspec-transformation-plan-v1` API with the
official OpenStatSpec Transformation Plan and SPSS-like Syntax Frontend
contracts from the package's pinned specification checkout. The release must
pass every case in the official Transformation Plan 0.2, SPSS Frontend 0.2,
and In-Place Transformation 0.2 manifests, including the referenced 0.1 golden
plans required by Frontend 0.2.

This is an intentional breaking change for `v0.6.0`. There is no compatibility
adapter for the package-local plan, and its non-standard `create_variable` and
`delete_variable` operations are removed from the transformation API.

## Contract boundary

The canonical model supports both official plan identifiers:

- `openstatspec-transformation-plan-v0.1` for programs containing only the
  unchanged 0.1 command subset; and
- `openstatspec-transformation-plan-v0.2` when a 0.2 command is present.

Supporting official 0.1 output is part of Frontend 0.2 conformance, not legacy
package compatibility. A plan contains exactly `contract`, `input_alias`, and
a non-empty ordered `operations` array. Dataset UUIDs, SQL details, actor
identity, and Dolt state do not belong in canonical plan JSON.

The package exposes typed plan objects plus a strict plan codec. Decoding first
validates the structure selected by `contract`, rejects unknown members and
non-canonical values, and then constructs typed operations. Encoding produces
the exact restricted-RFC-8785 bytes used for SHA-256 plan identity. It never
silently upgrades 0.1 to 0.2 or changes operation order, Unicode, expression
shape, or binary64 bits.

## Public API

The old constructors and method signatures are removed rather than deprecated.
The replacement boundary consists of:

- a `TransformationPlan` carrying contract, input alias, and typed operations;
- a `PlanCodec` for strict array/JSON decoding, canonical JSON, and plan hash;
- an `SpssFrontendRequest` carrying the 0.2 request contract, input alias,
  ordered input schema, and exact source text;
- an `SpssCompilationResult` carrying the emitted 0.1-or-0.2 plan and the
  LF-normalized source hash;
- an `SpssCompiler::compile(SpssFrontendRequest)` facade; and
- an `InPlaceApplyRequest` carrying the plan, input-alias-to-dataset binding,
  source hash, non-empty actor, and optional expected Dolt branch and HEAD.

`InPlaceTransformationExecutor::execute(InPlaceApplyRequest)` is the only
public mutation entry point. This makes the official alias-based plan portable
while keeping the concrete dataset identity and controlled execution context
explicit at apply time.

## Components

### Canonical model and validation

The current transformation model is replaced with the official 0.1 and 0.2
operation vocabulary. Version 0.1 includes `recode`, `set_variable_label`, and
`replace_value_labels`. Version 0.2 additionally includes `assign`,
`conditional_assign`, `set_format`, `set_measurement_level`, and `execute`.

Numeric literals retain exact 16-character lowercase binary64 bits. Operands,
comparisons, and n-ary boolean expressions are separate immutable types.
Validation rejects non-finite or negative-zero encodings, invalid target
states, string expressions, invalid formats, duplicate labels, reserved names,
and nested same-operator boolean nodes before SQL mutation.

### SPSS frontend

The lexer, parser, binder, and compiler remain separate stages, but they consume
the official request's ordered schema rather than a dataset UUID. Binding is
ASCII case-insensitive and stores the exact catalog spelling in the plan. It
tracks variables created by earlier commands and preserves the simultaneous
input semantics of multi-variable `RECODE`.

The existing 0.1 commands are narrowed to the official forms. Version 0.2 adds
`COMPUTE`, parenthesized `IF`, `FORMATS`, `VARIABLE LEVEL`, and `EXECUTE`.
Unsupported commands and expression forms fail closed. Decimal numeric tokens
are converted directly to correctly rounded binary64 bits with ties-to-even;
the conversion does not pass through a host floating-point intermediate, and
signed or underflowed zero becomes positive zero.

The compiler emits an exact 0.1 plan when the program contains only 0.1
commands. Any 0.2 command promotes the complete emitted plan to 0.2 without
dropping or reordering earlier operations.

### In-place SQL executor

Before mutation, the executor validates the complete plan, resolves the input
alias to exactly one dataset, binds every ordered operation against the evolving
schema, checks storage types and target state, verifies backend capabilities,
and validates the apply context. No journal or audit row is started until this
preflight succeeds.

Existing-target `recode` and `assign` use direct updates.
`conditional_assign` uses a direct update whose predicate relies on SQL
three-valued truth, so only TRUE rows change. Metadata operations update only
their normative catalog fields. `execute` emits no SQL and never commits.

SQLite and PostgreSQL may create a numeric target only where the full schema,
data, catalog, and audit change is proven to share one native transaction.
MySQL, MariaDB, and Dolt reject create-target plans before mutation with
`schema_change_not_atomic`; they never simulate atomicity with a copied table
or recovery artifact.

### Dolt context and audit

Every apply requires a non-empty actor. Dolt additionally requires the expected
branch and expected HEAD and a clean working set. Branch, HEAD, and cleanliness
are checked before mutation; branch and HEAD are checked again before success.
The executor never calls `DOLT_COMMIT`, switches branches, resets, merges, tags,
or creates a persistent recovery object.

The explicit catalog migration creates or upgrades the versioned
`transformation_apply` table. It stores one compact audit row with the
official binding contract, target identity, canonical plan and source hashes,
actor, status, operation count, timestamps, and optional Dolt evidence. The
table accepts both official 0.1 and 0.2 binding contracts and preserves any
existing official audit rows. Migration runs before an apply and is not hidden
inside the apply transaction.

## Data flow and atomicity

1. Validate the frontend request and normalize only source line endings for
   `source_hash`.
2. Parse, bind against the supplied schema, and compile the complete source.
3. Validate and canonicalize the emitted plan, then calculate `plan_hash`.
4. Build an apply request that maps the plan alias to one dataset and supplies
   actor and any Dolt expectations.
5. Preflight the entire apply without writing data, metadata, schema, or audit.
6. Execute operations in order inside one engine-native transaction and write
   the compact audit in that same boundary.
7. On any failure, roll back the native transaction and publish no successful
   audit or partial state.

A successful apply preserves dataset identity, physical table identity, case
order, case count, dataset count, and persistent data-table count. It creates
no derived dataset, full-table copy, staging relation, snapshot, rollback table,
dataset-version row, or hidden recovery layer. Dolt alone owns version history
and rollback.

## Diagnostics

Frontend and plan failures expose the stable specification diagnostic codes and
source spans where applicable. Apply failures expose the stable binding codes,
including actor, Dolt context, and backend capability failures. Exceptions may
add a concise human explanation but must not leak credentials, unrelated row
values, or driver connection strings.

Every validation, binding, capability, actor, branch, HEAD, and clean-working-set
failure occurs before mutation. A detected post-mutation Dolt context change
causes the open transaction to roll back.

## Verification

Automated tests load the manifests from the pinned OpenStatSpec `v0.3.0`
specification checkout and treat their expected canonical JSON, hashes, errors,
row results, metadata, audit fields, and forbidden artifacts as independent
goldens. Required coverage is:

- all 26 Transformation Plan 0.2 cases and every referenced Plan 0.1 golden;
- all 44 SPSS Frontend 0.2 cases and their exact source and plan hashes;
- all 11 In-Place Transformation 0.2 cases;
- focused unit tests for exact decimal-to-binary64 conversion, boolean
  precedence/flattening, version selection, and diagnostic spans;
- SQLite execution and rollback tests for successful target creation;
- PostgreSQL execution evidence for the supported atomic create path;
- MySQL, MariaDB, and Dolt rejection evidence for create-target plans;
- live Dolt success, mismatch, dirty-state, context-change, and no-commit
  evidence; and
- the existing full lint, style, PHPStan, PHPUnit, and GitHub service matrix.

The capability declaration and documentation claim Transformation Plan 0.1 and
0.2, SPSS Frontend 0.2, and In-Place Transformation 0.1 and 0.2 only after these
gates pass. Migration notes show the removed package-local API and the new
request/compile/apply flow, and the completed change is released as `v0.6.0`.

## Out of scope

Full IBM SPSS syntax, arbitrary arithmetic or functions, string predicates,
automatic target provisioning on non-atomic engines, additional frontends,
automatic Dolt commits, and OpenStatSpec-managed dataset versioning or rollback
are not part of this feature.
