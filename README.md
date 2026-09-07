# OpenStatSpec PHP

`openstatspec/php` is the PHP reference adapter for the [OpenStatSpec specification](https://github.com/OpenStatSpec/specification).

It imports an unencrypted SPSS `.sav` or `.zsav` dataset into a relational database as one source-faithful wide SQL table plus a metadata catalogue. It reconstructs that catalogue as a typed php-spss V3 `Dataset` and exports SAV or ZSAV.

## Status

PHP v0.7.1 targets released OpenStatSpec specification `v0.5.0` at immutable
commit `864e84479f554b8ee250ffed44c4dfb963750d4a`
(`specification_status: released`). Composer derives the package version from its
Git tag; it is independent of the specification version.

This is an early reference implementation. Its round-trip contract is **semantic**, not byte-identical: supported cases, order, variables, values, dictionary metadata and technical metadata are preserved; compression layout, timestamps and other writer-specific bytes are not promised.

SQLite, PostgreSQL 17.x/18.x, MySQL 8.4.x/9.7.x, MariaDB
11.4.x/11.8.x/12.3.x and exact Dolt 2.2.2/2.2.3 are implemented PDO write profiles.
The SAV/ZSAV 1.0 capability declaration selects
`database_io_policy: openstatspec-database-io-v1`: exports are database-read-only,
and default Dolt writes use the packaged exact-version policy without external
declaration files. Unknown patches (including 2.2.4) fail before mutation.
Server-family claims are conservative compatibility policies; CI records exact
evidence at PostgreSQL 17.10/18.4, MySQL 8.4.11/9.7.2 and MariaDB
11.4.12/11.8.8/12.3.2, and Dolt 2.2.2/2.2.3. Each service job verifies its live normalized product
version before the run counts as evidence.

### Supported database engines

| Engine / profile | Runtime support policy | Exact CI-tested versions |
| --- | --- | --- |
| SQLite core / `sqlite` | `>=3.24.0,<4.0.0` | Runtime-provided PDO SQLite; no pinned server image |
| PostgreSQL / `postgresql` | 17.x and 18.x | 17.10 and 18.4 |
| MySQL / `mysql` | 8.4.x and 9.7.x | 8.4.11 and 9.7.2 |
| MariaDB / `mariadb` | 11.4.x, 11.8.x, and 12.3.x | 11.4.12, 11.8.8, and 12.3.2 |
| Dolt / `dolt` | Writes: exact 2.2.2 and 2.2.3 only | 2.2.2 and 2.2.3 |

The PHP SQLite core profile remains `>=3.24.0,<4.0.0`. The Python adapter's
optional Transformation Workflow has its own narrower `>=3.35.0,<4.0.0`
policy; it does not change PHP support. Microsoft SQL Server is not supported
by this adapter and remains roadmap-only in the specification's
[MSSQL dialect roadmap](https://github.com/OpenStatSpec/specification/blob/main/docs/mssql-dialect-roadmap.md).

Each implemented profile follows one strict-wide contract:

1. One source dataset becomes one dedicated SQL data table.
2. One SPSS case becomes one SQL row.
3. One SPSS variable becomes one physical SQL column in source order.
4. `__case_ordinal` is the technical primary key that preserves case order and is never exported as an SPSS variable.
5. Separate catalogue tables preserve dictionary and operation metadata.

The catalogue retains source and physical variable names, storage kind and widths, labels, print/write formats, measurement/display metadata, typed value labels, user-missing rules, documents, technical file metadata, attributes, variable sets, multiple-response sets and roles. Numeric system-missing values are SQL `NULL`; user-missing values remain ordinary stored values and are described by metadata. Strings are non-null and an empty string remains a value.

Only unencrypted SAV and ZSAV are supported. Encrypted files, Portable (`.por`) files, EAV/cell tables, reshaping, automatic harmonisation, inferred respondent IDs and byte-identical reproduction are out of scope.

## Requirements

- PHP 8.4.1 or later
- `ext-pdo`
- Selected PDO driver: `pdo_sqlite`, `pdo_pgsql`, or `pdo_mysql`
- `openstatspec/spss-sav` 3.x, installed by Composer. The php-spss V3 engine needs `ext-bcmath`, `ext-mbstring` and `ext-zlib`.

Composer resolves dependencies against PHP 8.4.1, the package minimum.

## API

```php
use OpenStatSpec\Spss\GuardedImportSpssEngine;
use OpenStatSpec\Spss\SpssAdapter;

$pdo = new PDO('pgsql:host=localhost;dbname=statistics', $user, $password);
$adapter = new SpssAdapter($pdo);

$import = $adapter->import('/data/survey.zsav', 'survey_2026');
// SpssImportResult: operationId, datasetName, caseCount, diagnostics

$export = $adapter->export('survey_2026', '/data/survey-export.sav');
// SpssExportResult: datasetName, targetPath, caseCount, diagnostics, allowLoss
```

Export needs only read access to an already migrated catalogue and its wide
tables. Initialize or upgrade the catalogue with `SpssAdapter::migrateCatalog()`
using a write-capable deployment connection before using a read-only exporter;
an uninitialized or outdated catalogue fails with `catalog_migration_required`.
It reads authoritative normative metadata directly and never initializes,
migrates, synchronizes compatibility tables, or writes database audit records—even
on failure. `SpssExportResult` no longer has `operationId`; export creates no
`operation_id`. Diagnostics and accepted loss codes remain in the result.
The destination is replaced only after successful writing to a temporary file in
its directory; a writer failure preserves an existing destination. Injected
engines receive that temporary path and must use the Dataset's target format.

Server identity is still checked for reads, but export does not require a packaged
write-version claim or any external support declaration. Import, migration and
transformation write safeguards and tested server versions are unchanged.

Use `GuardedImportSpssEngine` when an engine must read from an ephemeral
descriptor while the adapter and database receive only a logical source path:

```php
$engine = new GuardedImportSpssEngine($innerEngine, $procFdPath, 'sav');
$adapter = new SpssAdapter($pdo, $engine);
$import = $adapter->import(
    $engine->logicalPath(),
    'survey_2026',
    verifiedSourceSha256: $verifiedSourceSha256,
);
```

`verifiedSourceSha256` must be exactly 64 lowercase hexadecimal characters.
The adapter persists it as `dataset.source_hash`, but validates only its shape:
the caller is responsible for proving that it hashes the exact bytes read by
the engine. Keep any physical guarded path, such as `/proc/self/fd/...`,
internal to the engine; `SpssAdapter::import()` rejects exact Linux
descriptor paths under `/proc/*/fd/` and `/dev/fd/` before any database
mutation. `GuardedImportSpssEngine` also recursively rejects descriptor
paths in inner-engine identity keys or values and replaces every inner read
exception with a neutral logical-source error. Sanitized errors do not chain
the original exception, so descriptor paths cannot enter operation or fidelity
journals through identity metadata or read failures. The adapter and catalogue
need only the logical `.sav`/`.zsav` path
and the verified hash. Omitting the argument preserves the
existing behavior: a readable source file is hashed by pathname, otherwise
`dataset.source_hash` is `NULL`.

### Fidelity policy

Export is fail-closed. If an exporter reports a known fidelity diagnostic, it does **not** write a file until the caller explicitly accepts its code:

```php
$export = $adapter->export(
    'survey_2026',
    '/data/survey-export.sav',
    allowLoss: ['example_diagnostic_code'],
);
```

Pass only loss codes consciously accepted for that conversion. `operation_catalog` records journaled imports; `fidelity_event_catalog` records their emitted diagnostics. Import preflight failures after catalogue readiness are auditable even when no dataset was created. Each journaled import also records the selected SPSS engine package and Composer version in `engine_details`. Export returns diagnostics or throws without recording an operation.

## Transformation API

The adapter claims official Transformation Plan 0.1/0.2, SPSS Syntax Frontend
0.2, and In-Place Transformation 0.1/0.2 conformance. Compile an alias-based
frontend request, then bind that alias to the existing dataset at apply time.
The specification pin does not claim support for optional Transformation Plan,
SPSS Syntax Frontend, or In-Place Transformation 0.3.

```php
use OpenStatSpec\Frontend\Spss\Request\SpssFrontendRequest;
use OpenStatSpec\Frontend\Spss\SpssCompiler;
use OpenStatSpec\Sql\Connection;
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

Run `SpssAdapter::migrateCatalog()` before apply. SQLite and PostgreSQL may
create numeric targets atomically; MySQL, MariaDB, and Dolt require target
columns and catalog entries to be pre-provisioned. Dolt additionally requires
the expected branch and HEAD plus a clean working set. The executor never
creates a Dolt commit: inspection and commit policy remain caller-owned.

Version 0.6.0 removes the package-local
`openstatspec-transformation-plan-v1` API and its non-standard SPSS `STRING`
and `DELETE VARIABLES` transformation commands without a compatibility layer.
See [docs/transformations.md](docs/transformations.md) for migration details,
supported operations, and atomicity guarantees.

## Architecture

- `src/Core` - diagnostics and fail-closed fidelity policy.
- `src/Sql` - PDO profiles, strict-wide DDL, import/export and catalogues.
- `src/Spss` - SAV/ZSAV gating, typed V3 engine bridge and public adapter API.
- `src/Transformation` - official plans, compact apply audit, and in-place execution.
- `src/Frontend/Spss` - official SPSS Frontend 0.2 request compilation.

See [docs/architecture.md](docs/architecture.md) for the complete relational contract.
See [docs/transformations.md](docs/transformations.md) for frontend boundaries,
official contract identifiers, in-place guarantees, and migration guidance.

## Upgrading an existing catalogue

After upgrading the package, run the catalogue migration once before importing or exporting:

```php
$adapter = new SpssAdapter($pdo);
$adapter->migrateCatalog();
```

The migration is idempotent. It upgrades the compatibility catalogue, creates the versioned normative OpenStatSpec catalogue, and backfills datasets imported by earlier adapter versions. Back up a production database before package upgrades and run this call from the application's normal deployment migration. The current catalogue migration version is recorded in `openstatspec_schema_migration`.

## Deployment isolation

OpenStatSpec uses generic, unqualified catalogue names such as `dataset`,
`variable`, `operation`, and `fidelity_event`. Give the adapter a dedicated
database namespace and a PDO connection whose namespace cannot be changed by
unrelated application code while an adapter operation is running:

- PostgreSQL: create a dedicated schema and use a dedicated connection with a
  fixed `search_path` containing that schema only.
- MySQL/MariaDB/Dolt: select a dedicated database in the adapter DSN.
- SQLite: use a dedicated database file and connection.

The machine-readable capability declaration must expose the active namespace
under `active_connection`. Check that value against the intended deployment namespace
before importing. See [the architecture guide](docs/architecture.md#deployment-namespace-and-connection-isolation)
for the isolation contract and examples.

## Large-file memory probe

The current adapter is **not streaming**. The SPSS engine materializes a typed
dataset, import normalization retains its rows, and export reconstructs a full
dataset before writing. Peak memory therefore depends on the supplied file's
case count, variable count, string sizes, and metadata.

Measure a representative, user-supplied SAV or ZSAV file in an isolated process:

```bash
php tools/memory-probe.php --source=/data/representative-large.sav
```

The command creates a temporary dedicated SQLite database, performs one semantic
import/export round trip, removes its temporary artifacts, and prints one JSON
report to standard output. It reports input size, case count, PHP `memory_limit`,
baseline memory, and process peak memory. It deliberately declares
`streaming: false` and does not infer a universal safe file-size limit.

To retain the generated SQLite database and exported SPSS file for inspection,
provide a new database path and keep the artifacts:

```bash
php tools/memory-probe.php \
  --source=/data/representative-large.zsav \
  --database=/tmp/openstatspec-memory-probe.sqlite \
  --keep-artifacts
```

## Testing and CI

Run the local gate before committing:

```bash
composer install
# Checkout specification v0.5.0 at 864e84479f554b8ee250ffed44c4dfb963750d4a first.
OPENSTATSPEC_SPECIFICATION_DIR=/path/to/exact-specification-checkout composer check
```

Install the tracked pre-commit hook once per clone:

```bash
bash tools/install-git-hooks.sh
```

`composer check` validates Composer configuration, lints PHP, checks style, runs PHPStan and runs PHPUnit. Use `composer fix` for safe style fixes, then rerun `composer check`.

GitHub Actions runs the regular suite on PHP 8.4 and 8.5. It also runs real
SAV and ZSAV integration round trips against exact PostgreSQL 17.10/18.4,
MySQL 8.4.11/9.7.2, MariaDB 11.4.12/11.8.8/12.3.2, and Dolt
2.2.2/2.2.3.
Those checks use their PDO drivers and php-spss V3 read/write paths, not only
DDL snapshots. Other engines retain family policies; the packaged Dolt write
list is limited to the exact 2.2.2 and 2.2.3 versions tested by existing CI.
It does not infer evidence or support for any other patch.

CI runs `DoltReadOnlyExportTest` on both exact Dolt 2.2.2 and 2.2.3 with
root/root admin credentials. Locally opt-in SELECT-only export coverage
(also usable on Dolt 2.3.0) creates and
removes its own unique test database and user. It checks SAV/ZSAV output, failure
safety and unchanged working/staged roots and history, without expanding write
support:

```bash
OPENSTATSPEC_DOLT_READ_ONLY_ADMIN_DSN='mysql:host=127.0.0.1;port=13387;charset=utf8mb4' \
  vendor/bin/phpunit tests/Integration/DoltReadOnlyExportTest.php
```

The test defaults to `root` with an empty password; optional
`OPENSTATSPEC_DOLT_READ_ONLY_ADMIN_USER` and
`OPENSTATSPEC_DOLT_READ_ONLY_ADMIN_PASSWORD` override these test credentials.

## Contributing

The normative model lives in the [OpenStatSpec specification repository](https://github.com/OpenStatSpec/specification). Contributions are welcome for strict-scope adapters, database profiles, SAV/ZSAV fixtures, conformance tests and documentation.

New work must preserve the source-faithful wide-table contract, retain supported SPSS semantics in catalogue metadata and emit explicit diagnostics for unsupported conversion or capability limits. Include focused tests and run `composer check` before opening a pull request.

## Framework use

The package is framework-neutral and has no Yii2 or Laravel dependency. Applications supply their own PDO connection; a framework integration may wrap that connection but must not replace the OpenStatSpec mapping.

## SPSS engine

The selected engine is [OpenStatSpec/php-spss](https://github.com/OpenStatSpec/php-spss), consumed as Composer dependency `openstatspec/spss-sav` 3.x and maintained alongside this adapter.
