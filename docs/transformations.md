# Transformations

## Official contracts

The PHP adapter conforms to these pinned OpenStatSpec contracts:

- `openstatspec-transformation-plan-v0.1` and
  `openstatspec-transformation-plan-v0.2`;
- `openstatspec-spss-syntax-frontend-v0.2` and opt-in
  `openstatspec-spss-syntax-frontend-v0.3`; and
- `openstatspec-in-place-transformation-v0.1` and
  `openstatspec-in-place-transformation-v0.2`.

PHP v0.8.0 includes official opt-in `openstatspec-spss-syntax-frontend-v0.3`,
with local fixture/SQLite evidence and a passed implementation service CI matrix.
It is included in `official_frontend_contracts`; the retained
`opt_in_frontend_contracts` field reports `official_conformant` for 0.3.
Frontend 0.2 remains the default. Plan 0.3 and In-Place 0.3 are not implemented.

Plans are source-neutral, alias-based, and deterministic. The canonical plan
contains only its contract, input alias, and ordered operations. Dataset UUIDs,
SQL identifiers, actor identity, and Dolt context are supplied only when the
plan is applied.

`OpenStatSpec\Transformation\Plan` contains the immutable official model and
strict `PlanCodec`. `OpenStatSpec\Frontend\Spss` parses and binds an
`SpssFrontendRequest` to an `SpssCompilationResult`. The separate
`OpenStatSpec\Transformation\Execution` layer binds the plan alias to an
existing dataset and mutates its registered wide table.

## Compile and apply

```php
use OpenStatSpec\Frontend\Spss\Request\SpssFrontendRequest;
use OpenStatSpec\Frontend\Spss\SpssCompiler;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Spss\SpssAdapter;
use OpenStatSpec\Transformation\Execution\InPlaceApplyRequest;
use OpenStatSpec\Transformation\Execution\InPlaceTransformationExecutor;

$compiled = (new SpssCompiler())->compile(SpssFrontendRequest::fromArray($request));
$apply = new InPlaceApplyRequest(
    plan: $compiled->plan,
    inputAlias: 'parent',
    datasetId: $datasetId,
    sourceHash: $compiled->sourceHash,
    actor: 'analyst@example.org',
    expectedBranch: $branch,
    expectedHead: $head,
);
$result = (new InPlaceTransformationExecutor(new Connection($pdo)))->execute($apply);
```

The request carries the official frontend contract, input alias, ordered input
schema, and exact source text. The compiler emits Plan 0.1 when every command
belongs to the 0.1 subset and Plan 0.2 as soon as any 0.2 command is present.
`PlanCodec` strictly decodes official plan JSON and produces its canonical JSON
and SHA-256 identity without changing operation order or binary64 values.

Before applying transformations to an existing catalog, deployment must run
the explicit catalog migration:

```php
$adapter = new SpssAdapter($pdo);
$adapter->migrateCatalog();
```

The migration provisions the compact `transformation_apply` audit table. Apply
does not hide schema migration inside its transaction.

## Supported operations and syntax

Plan 0.1 contains ordered `recode`, `set_variable_label`, and
`replace_value_labels` operations. Plan 0.2 additionally contains `assign`,
`conditional_assign`, `set_format`, `set_measurement_level`, and `execute`.

SPSS Frontend 0.2 supports the corresponding restricted forms of:

- `RECODE`, including `INTO`, exact/range/system-missing inputs, and explicit
  unmatched behavior;
- `VARIABLE LABELS` and `VALUE LABELS`;
- `COMPUTE` and parenthesized numeric `IF` predicates;
- `FORMATS` with the numeric `F` family;
- `VARIABLE LEVEL`; and
- `EXECUTE`.

Names bind ASCII case-insensitively while plans retain exact catalog spelling.
Multi-variable `RECODE` reads all sources from the pre-command schema. Commands,
expressions, comments, and syntax outside the official subset fail closed.

## Opt-in Frontend 0.3

Select the exact `openstatspec-spss-syntax-frontend-v0.3` request contract, also
available as `SpssFrontendRequest::CONTRACT_V03`. `CONTRACT` and the public
parse/bind defaults remain 0.2. The existing `fromArray()` production boundary
rejects unknown/missing fields and wrong types, including nested schema and
typed-label fields. No physical identifiers or schema extensions are accepted.

The shared parser/binder adds command-boundary `*` and `COMMENT` comments,
non-nested block comments wherever whitespace is legal, dictionary-order `TO`,
grouped RECODE/labels/formats/levels, numeric `NOT` and `NE`/`<>`/`~=`, finite
LOWEST/HIGHEST bounds, and ordered typed `ADD VALUE LABELS`. Comments end at
the next command period (or `*/` for blocks); nested/unterminated blocks and
comment-only programs fail. Hashing retains original comments and normalizes
only CRLF/CR to LF. ADD updates existing typed codes in place and appends new
codes, including after preceding VALUE LABELS replacements. Its initial label
state comes from the supplied input schema; callers must supply the current
ordered dictionary and typed value labels when compiling. Compilation is pure
over request metadata: the compiler does not query or mutate the database.

**Parent decision:** use conventional SPSS precedence: comparisons, then NOT,
then AND, then OR. Thus `NOT a = 1 AND b = 2 OR c = 3` means
`((NOT (a = 1)) AND (b = 2)) OR (c = 3)`. Parentheses override that order.
Negation complements comparisons and applies De Morgan's laws without changing
UNKNOWN; canonical lowering flattens maximal same-operator nodes in source
order, including across parentheses. Double NOT retains the original predicate.

These additions emit exact Plan 0.1 when possible and Plan 0.2 for existing
0.2-only operations. `STRING`, `DELETE VARIABLES`, arbitrary expressions and
implicit type coercions remain rejected. No codec, catalog or executor schema
change is involved.

## In-place and atomicity contract

Every successful apply preserves the existing logical dataset UUID, registered
physical table identity, case order, case count, dataset count, and persistent
data-table count. Existing-target recodes and assignments use direct updates;
metadata operations change only their normative catalog fields.

The executor validates the entire plan, evolving schema, actor, backend
capabilities, and Dolt context before mutation. It then applies every operation
and writes one compact success audit inside one engine-native transaction. A
failure rolls that transaction back and publishes no failed transformation
audit row.

The transformation path never creates a derived dataset, persistent output or
staging table, full-table copy, snapshot, rollback table, dataset-version row,
or hidden recovery layer. It does not use the import/export operation journal
as a second transformation history.

SQLite and PostgreSQL can create a numeric target inside the native apply
transaction. MySQL, MariaDB, and Dolt cannot make that schema change atomic, so
deployment must pre-provision and catalog every intended target before apply;
a create-target plan fails before mutation with `schema_change_not_atomic`.

## Dolt ownership

Dolt applies require a non-empty caller-supplied actor, expected branch, and
expected HEAD plus a clean working set. The executor verifies branch and HEAD
before mutation and again before success. It never switches branches, commits,
resets, merges, or tags the repository.

A successful Dolt apply intentionally leaves an inspectable working-set diff.
Dolt commits and all version-history decisions remain caller-owned; the audit
stores only compact before/after evidence.

## v0.6.0 migration

Version 0.6.0 removes the package-local
`openstatspec-transformation-plan-v1` API without a compatibility adapter. It
also removes the non-standard SPSS `STRING` and `DELETE VARIABLES`
transformation commands. Callers must move to `SpssFrontendRequest`,
`SpssCompilationResult`, `PlanCodec`, `InPlaceApplyRequest`, and the official
contract identifiers shown above.

On MySQL, MariaDB, and Dolt, deployment migrations must create and catalog new
targets before applying an official plan. On Dolt, the caller must also capture
the expected branch and HEAD, apply against a clean working set, inspect the
resulting diff, and decide whether and how to commit it.

## Conformance and development gates

The official fixture suites are loaded from the pinned OpenStatSpec
specification checkout:

```bash
vendor/bin/phpunit tests/Transformation/Conformance
vendor/bin/phpunit tests/Frontend/Spss/Conformance
vendor/bin/phpunit tests/Integration/OfficialInPlaceTransformation01Test.php
vendor/bin/phpunit tests/Integration/OfficialInPlaceTransformation02Test.php
composer check
```

They cover all 4 Plan 0.1, 26 Plan 0.2, 44 Frontend 0.2, 6 In-Place 0.1,
and 11 In-Place 0.2 manifest cases, plus all 90 effective Frontend 0.3 cases
(35 declared plus inherited cases minus the two comment supersessions).
Frontend checks compare source/plan hashes, exact inherited plans, diagnostics
and declared metadata preservation. Use
`OPENSTATSPEC_SPECIFICATION_DIR=/tmp/openstatspec-alignment-spec` for the
alignment checkout at unchanged commit `864e84479f554b8ee250ffed44c4dfb963750d4a`.
The public Frontend 0.3 → native SQLite apply regression checks UNKNOWN,
metadata, provenance and unchanged dataset/table identity without extra data
artifacts or history. All 20 jobs in the
[implementation CI run](release-readiness.md#frontend-03-implementation-ci-evidence)
passed, including PostgreSQL, MySQL, MariaDB and Dolt service coverage.
Full CI on the final 0.8.0 release commit remains required before tagging.
SQLite runs locally; network-service cases run when their `OPENSTATSPEC_*`
configuration is supplied. Local skips are not additional service evidence.
