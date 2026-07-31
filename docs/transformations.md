# Transformations

## Purpose and boundary

The transformation layer applies small, deterministic edits to an existing
OpenStatSpec dataset. Its canonical `TransformationPlan`, validation, and SQL
executor do not depend on SPSS, Stata, SAS, or another statistics package.
Language-specific syntax belongs to a frontend that compiles into the same
canonical plan.

The initial frontend implements a documented subset of SPSS transformation
syntax. The Stata and SAS directories are placeholders only. Their presence is
an architectural reservation, not a support claim.

## In-place contract

Every successful apply preserves both identities:

- the existing `dataset.dataset_id`; and
- the existing `dataset.physical_table_schema` plus
  `dataset.physical_table_name`.

The executor updates that wide table and its existing normative metadata
catalog in place. It does not create a derived dataset, persistent output or
staging table, full-table copy, snapshot table, hidden rollback table, or a
parallel OpenStatSpec version. A successful edit therefore does not increase
the persistent dataset count or physical data-table count.

The database engine's native transaction is used where it can make the
operation atomic. OpenStatSpec does not add a durable undo or recovery-version
layer around engines whose DDL commits implicitly. Dolt remains the history,
diff, branch, and rollback layer when Dolt is the selected SQL server.

## Architecture

The package separates four responsibilities:

1. `OpenStatSpec\Transformation\Model` defines the canonical, typed plan and
   operations.
2. `OpenStatSpec\Transformation\Validation` validates plans without knowing
   their source language or SQL dialect.
3. `OpenStatSpec\Frontend\Spss` lexes, parses, binds, and compiles the supported
   SPSS subset into a canonical plan.
4. `OpenStatSpec\Transformation\Execution` resolves catalog identities and
   applies a validated plan through the active PDO profile.

The SQL executor accepts a completed plan. It never invokes the SPSS parser.
Likewise, the SPSS frontend does not issue SQL or select a database profile.
This is the package boundary a future real frontend must use.

Canonical serialization is deterministic. The plan hash identifies the exact
validated operation sequence; source text and language provenance stay outside
the source-neutral plan.

## Supported operations

The canonical layer supports:

- ordered recode rules with exact values, numeric ranges, missing values, and
  exactly one explicit final else rule;
- assigning a scalar value, copying the source value, or assigning system
  missing;
- variable-label replacement; and
- complete value-label replacement for one variable.

Recode rules use first-match semantics. Validation rejects overlapping,
duplicate, or ill-typed rules before SQL mutation. An SPSS frontend plan always
meets the canonical explicit-else contract: when source syntax omits `ELSE`,
the compiler adds SPSS's context-appropriate default action. Variables are
resolved through the normative `variable` catalog and physical identifiers
are quoted by the active PDO SQL profile; callers cannot supply raw table or
column SQL.

## SPSS frontend scope

The SPSS frontend recognizes the documented transformation subset:

- `RECODE ... INTO ...` with exact values, `THRU` ranges,
  `LOWEST`, `HIGHEST`, `SYSMIS`, `ELSE`, `COPY`, and `SYSMIS`
  outputs;
- `VARIABLE LABELS`; and
- `VALUE LABELS`.

Keywords are case-insensitive. Dataset variable references currently must
match the normative `variable.source_name` spelling exactly; this documented
subset does not claim SPSS's case-insensitive symbol binding. The `MISSING`
selector fails closed because SPSS user-missing semantics require binding the
dataset's `missing_rule` metadata; use `SYSMIS` for system missing or list
supported explicit values. Statements end with a period. Unsupported SPSS
commands fail closed with a frontend diagnostic; they are not silently skipped
or passed to an external statistics engine. This package does not claim full
SPSS syntax compatibility.

## SQL profiles and Dolt

Transformations are not restricted to Dolt. The executor uses every SQL
connection profile implemented by this package: SQLite, PostgreSQL,
MySQL/MariaDB, and Dolt.

Dolt adds safety evidence rather than acting as a gateway. Before mutation the
executor checks the active branch, resolves `HEAD`, and requires a clean Dolt
working set. After mutation it verifies that branch and `HEAD` did not change
under the operation. The executor does not switch branches or create a Dolt
commit. The caller owns the later review and commit policy.

MySQL-family DDL commits implicitly. A recode into a new physical target column
can only be part of one native atomic apply on a profile with transactional DDL.
On MySQL, MariaDB, and Dolt, create and catalog the intended target variable in
the deployment workflow before applying a recode to it. Existing-column
recodes and metadata edits remain supported. This capability boundary avoids
pretending that a compensating copy or OpenStatSpec rollback layer is atomic.

SQLite and PostgreSQL may create a new numeric target column inside their
native transaction. A new string target must be registered on every profile
before execution so its normative `declared_string_width` is explicit.

The machine-readable capability declaration reports in-place transformations
as supported for every implemented SQL profile and states these target-creation
boundaries. Dolt additionally reports its clean-working-set and stable
branch/HEAD guard.

## Minimal PHP flow

```php
use OpenStatSpec\Frontend\Spss\SpssCompiler;
use OpenStatSpec\Sql\Connection;
use OpenStatSpec\Transformation\Execution\InPlaceTransformationExecutor;

$datasetId = '018f47a2-4c10-7d34-8f11-93b1c3efc321';
$syntax = 'RECODE score (1=10) (ELSE=COPY).';

$plan = (new SpssCompiler())->compile($syntax, $datasetId);
$result = (new InPlaceTransformationExecutor(new Connection($pdo)))->execute($plan);
```

`$pdo` must already point to the dedicated OpenStatSpec catalog namespace.
The executor verifies the catalog ownership marker and resolves the same
`dataset_id` and physical wide table before mutation.

## Development commands

Install dependencies and run the complete local gate from the PHP repository:

```bash
composer install
composer check
```

Run only transformation tests while developing the layer:

```bash
vendor/bin/phpunit tests/Transformation tests/Frontend/Spss
```

Apply the formatter, then rerun the complete gate:

```bash
composer fix
composer check
```

Database integration checks require the corresponding PDO driver and server.
They must use a dedicated OpenStatSpec namespace, as described in the
[architecture guide](architecture.md#deployment-namespace-and-connection-isolation).

## Operational checklist

Before applying a plan:

1. verify that the connection uses the intended dedicated OpenStatSpec
   namespace;
2. select the existing dataset by its canonical UUID;
3. compile source syntax explicitly with the intended frontend, or construct a
   canonical plan directly;
4. validate the plan before any mutation;
5. on Dolt, start from the expected branch and a clean working set; and
6. after success, inspect the data and metadata diff and decide separately
   whether to create a Dolt commit.

OpenStatSpec stores only compact operation evidence such as the plan identity
and relevant Dolt state. It never stores copied row state as transformation
audit data.
