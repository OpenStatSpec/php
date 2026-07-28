# OpenStatSpec PHP

`openstatspec/php` is the PHP reference adapter for the [OpenStatSpec specification](https://github.com/OpenStatSpec/specification).

It imports an unencrypted SPSS `.sav` or `.zsav` dataset into a relational database as one source-faithful wide SQL table plus a metadata catalogue. It reconstructs that catalogue as a typed php-spss V3 `Dataset` and exports SAV or ZSAV.

## Status

This is an early reference implementation. Its round-trip contract is **semantic**, not byte-identical: supported cases, order, variables, values, dictionary metadata and technical metadata are preserved; compression layout, timestamps and other writer-specific bytes are not promised.

SQLite, PostgreSQL 17/18, MySQL 8.4/9.7 and MariaDB 11.4/11.8/12.3 are implemented PDO profiles. Each follows one strict-wide contract:

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
- `tiamo/spss` 3.x, installed by Composer. The php-spss V3 engine needs `ext-bcmath`, `ext-mbstring` and `ext-zlib`.

Composer resolves dependencies against PHP 8.4.1, the package minimum.

## API

```php
use OpenStatSpec\Spss\SpssAdapter;

$pdo = new PDO('pgsql:host=localhost;dbname=statistics', $user, $password);
$adapter = new SpssAdapter($pdo);

$import = $adapter->import('/data/survey.zsav', 'survey_2026');
// SpssImportResult: operationId, datasetName, caseCount, diagnostics

$export = $adapter->export('survey_2026', '/data/survey-export.sav');
// SpssExportResult: operationId, datasetName, caseCount, diagnostics, allowLoss
```

### Fidelity policy

Export is fail-closed. If an exporter reports a known fidelity diagnostic, it does **not** write a file until the caller explicitly accepts its code:

```php
$export = $adapter->export(
    'survey_2026',
    '/data/survey-export.sav',
    allowLoss: ['example_diagnostic_code'],
);
```

Pass only loss codes consciously accepted for that conversion. `operation_catalog` records successful and failed imports/exports; `fidelity_event_catalog` records emitted diagnostics. A failed preflight is therefore auditable even when it created no dataset. Each operation also records the selected SPSS engine package and Composer version in engine_details.

## Architecture

- `src/Core` - diagnostics and fail-closed fidelity policy.
- `src/Sql` - PDO profiles, strict-wide DDL, import/export and catalogues.
- `src/Spss` - SAV/ZSAV gating, typed V3 engine bridge and public adapter API.

See [docs/architecture.md](docs/architecture.md) for the complete relational contract.

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
- MySQL/MariaDB: select a dedicated database in the adapter DSN.
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
composer check
```

`composer check` validates Composer configuration, lints PHP, checks style, runs PHPStan and runs PHPUnit. Use `composer fix` for safe style fixes, then rerun `composer check`.

GitHub Actions runs the regular suite on PHP 8.4 and 8.5. It also runs real SAV and ZSAV integration round trips against PostgreSQL 17 and 18, MySQL 8.4 and 9.7, and MariaDB 11.4, 11.8 and 12.3. Those profile checks use their PDO drivers and php-spss V3 read/write paths, not only DDL snapshots.

## Contributing

The normative model lives in the [OpenStatSpec specification repository](https://github.com/OpenStatSpec/specification). Contributions are welcome for strict-scope adapters, database profiles, SAV/ZSAV fixtures, conformance tests and documentation.

New work must preserve the source-faithful wide-table contract, retain supported SPSS semantics in catalogue metadata and emit explicit diagnostics for unsupported conversion or capability limits. Include focused tests and run `composer check` before opening a pull request.

## Framework use

The package is framework-neutral and has no Yii2 or Laravel dependency. Applications supply their own PDO connection; a framework integration may wrap that connection but must not replace the OpenStatSpec mapping.

## SPSS engine

The selected engine is [TonisOrmisson/php-spss](https://github.com/TonisOrmisson/php-spss), consumed as Composer dependency `tiamo/spss` 3.x. It is an external dependency, not an OpenStatSpec-maintained codebase.
