# Architecture

## Purpose

This package is a reference implementation of OpenStatSpec. The [specification repository](https://github.com/OpenStatSpec/specification) is authoritative for the data model and conformance rules.

It provides a narrow, source-faithful mapping of unencrypted SPSS SAV and ZSAV datasets to PDO-backed relational databases and back. It is not a statistics engine, survey system, EAV store or data-harmonisation layer.

## Strict source-faithful contract

For one imported SPSS source dataset:

1. One source dataset maps to exactly one dedicated SQL data table.
2. One SPSS case maps to exactly one SQL row.
3. One SPSS variable maps to exactly one physical SQL column, preserving source order.
4. Reserved technical `__case_ordinal` preserves case order and is not exported as an SPSS variable.
5. The source variable name remains authoritative; the catalogue maps it losslessly to a deterministic, dialect-safe physical identifier.
6. Dictionary metadata remains in catalogue relations, not data cells.

The adapter must not create EAV/cell tables, long views, chunked tables, reshaped data, automatic harmonisation or inferred respondent keys.

Numeric SPSS system-missing values map to SQL `NULL`. User-missing values remain raw numeric or string values, with discrete/range semantics in the catalogue. String columns are non-null; blank strings are values. SPSS date/time/currency values stay numeric with SPSS format metadata rather than being coerced to SQL temporal or decimal types.

## Profiles and coverage

The public `SpssAdapter` chooses a profile from the PDO driver:

| PDO driver | Profile | Current coverage |
| --- | --- | --- |
| `sqlite` | SQLite | in-memory unit and round-trip suite |
| `pgsql` | PostgreSQL | live PostgreSQL 17 and 18 SAV/ZSAV CI round trips |
| `mysql` | MySQL/MariaDB | live MySQL 8.4/9.7 and MariaDB 11.4/11.8/12.3 SAV/ZSAV CI round trips |
| `mysql` | Dolt | live Dolt 2.2.2 SAV/ZSAV CI round trips; detected by `@@version_comment` plus `DOLT_VERSION()` |

Every profile creates the same logical strict-wide layout and metadata catalogue. Physical SQL types, identifier limits and capability preflight are profile-specific. A source that cannot be represented must be rejected before an incomplete substitute is created.

## Catalogue and recovery

The catalogue is the semantic dictionary for the physical data table. It records, as applicable:

- dataset and physical table identity;
- source ordering, source/physical variable mapping, storage kind, widths and labels;
- print/write formats, measurement level and display properties;
- typed ordered value labels and all supported user-missing forms;
- documents and technical file metadata;
- file/variable attributes, variable sets, multiple-response sets and roles; and
- import/export operation records and fidelity events.

`SpssImportResult` carries an operation ID, dataset name, case count and diagnostics. `SpssExportResult` carries the same operational evidence plus explicitly accepted loss codes.

## Round trip and fidelity policy

The objective is semantic equivalence for represented features, not byte identity. Original compression blocks, product-specific bytes, timestamps and other incidental writer representation are not promised.

An exporter may emit machine-readable `FidelityDiagnostic` values for a known loss boundary. Export is fail-closed: it refuses to write until each emitted diagnostic code is present in the caller's `allowLoss` list. That makes intentional lossy conversion explicit rather than silently producing a downgraded file.

The singular OpenStatSpec `operation` and `fidelity_event` tables are the authoritative audit record. Legacy `operation_catalog` and `fidelity_event_catalog` remain mirrored only for transition compatibility. A failed preflight creates a failed `operation` and at least one `fidelity_event` with a null dataset reference, direction, event code, source item and timestamp.

## SAV/ZSAV boundary

The adapter accepts and writes unencrypted `.sav` and `.zsav` only. php-spss V3 parses and writes the file; OpenStatSpec owns normalization, profile mapping, catalogue persistence, reconstruction and fidelity enforcement.

Encrypted files, Portable (`.por`) files and arbitrary external-engine formats are rejected. A future source adapter must follow the same strict-wide and diagnostic contract and must not silently reshape data.

## Package layers

### Core

`src/Core` contains diagnostic codes, operation/fidelity policy and explicit unsupported-operation errors.

### SQL

`src/Sql` owns PDO profile selection, dialect-safe strict-wide DDL, catalogue persistence, import/export reconstruction, capability preflight and the operation journal.

### SPSS

`src/Spss` owns SAV/ZSAV extension gating, external-engine normalization, typed php-spss V3 bridging and the public `SpssAdapter` API.

## External engine

The selected engine is [TonisOrmisson/php-spss](https://github.com/TonisOrmisson/php-spss), Composer dependency `tiamo/spss` 3.x. It is external. If a compatible reader or writer is unavailable, the adapter produces an explicit `external_engine_unavailable` diagnostic rather than pretending conversion succeeded.

## Framework boundary

This package requires neither Yii2 nor Laravel. Applications supply a PDO connection. A framework integration may provide connection wiring, migrations or CLI commands, but must call this adapter rather than reimplement the mapping.

## Standard catalogue and upgrades

Every normal import writes the singular OpenStatSpec catalogue tables from the
normative schema: `dataset`, `variable`, value-label and missing-rule tables,
attributes, `document`, variable/multiple-response sets, `operation`, and
`fidelity_event`. The older plural tables are a compatibility read model for
existing exports; they are not the standard contract for a newly imported
dataset.

Before upgrading an existing database explicitly, run:

```php
$adapter = new SpssAdapter($pdo);
$adapter->migrateCatalog();
```

The command creates and versions the canonical catalogue through
`openstatspec_schema_migration`; it also applies the write-format migration to
SQLite, MySQL/MariaDB/Dolt and PostgreSQL compatibility catalogues, then backfills
each exportable legacy dataset into the singular standard tables.

A completely empty dedicated namespace is initialized automatically on its
first ordinary import or export attempt. An existing catalogue is never
upgraded implicitly: when its identity or validated pre-identity state is older,
ordinary use fails with `catalog_migration_required` before journal or schema
mutation, and deployment must call `migrateCatalog()` explicitly.

MySQL/MariaDB DDL has implicit commits. The adapter preflights before creating
a table and performs compensating cleanup after a later failure. If cleanup
itself fails, it is reported as an error requiring operator inspection rather
than being silently ignored.

## Deployment namespace and connection isolation

The adapter intentionally uses the generic catalogue names defined by the
standard. It does not add an OpenStatSpec product prefix and does not qualify
every SQL statement with a caller-supplied namespace. A production deployment
must therefore dedicate the active database namespace to one OpenStatSpec
catalogue.

The capability declaration's `active_connection` object exposes the resolved
namespace and its runtime inventory-verification status. The adapter rejects
unrelated tables; only its known catalogue relations and physical wide tables
registered by `dataset` or `datasets` are allowed. Deployment must still use
a dedicated namespace and appropriate database permissions.

### PostgreSQL

Create a schema for the catalogue and data tables, grant the adapter principal
access to that schema, and use a dedicated PDO connection with a fixed
`search_path`. Do not include `public` or user-controlled schemas in that
connection's effective path.

```sql
CREATE SCHEMA openstatspec AUTHORIZATION openstatspec_app;
ALTER ROLE openstatspec_app IN DATABASE statistics
  SET search_path = openstatspec;
```

Verify `current_schema()` and `current_schemas(false)` on the exact connection
passed to `SpssAdapter`. Do not change `search_path` while an import, export, or
catalogue migration is running.

### MySQL, MariaDB and Dolt

Create and select a dedicated database in the PDO DSN, for example
`dbname=openstatspec`. Grant the adapter principal only the required privileges
on that database. Verify `DATABASE()` on the exact adapter connection before
importing.

### SQLite

Use a dedicated database file rather than attaching OpenStatSpec catalogue
tables to an application's existing file. Do not share the adapter PDO object
with code that runs `ATTACH`, `DETACH`, transaction-control statements, or
schema migrations during an adapter operation.

## Memory behaviour

The current PHP implementation is fully buffered and does not claim streaming:

1. php-spss reads the source into a typed in-memory `Dataset`;
2. normalization exposes the dataset rows to the SQL importer; and
3. export reads the cases and reconstructs a complete `Dataset` before the SPSS
   writer emits the target file.

Memory use is consequently data-dependent and grows with cases, variables,
encoded string values, and dictionary metadata. A database engine's row or
value limit is not a PHP memory guarantee. Deployments must measure a
representative high-end file under the same PHP version, extensions,
`memory_limit`, and SPSS engine version used in production.

`tools/memory-probe.php` provides a reproducible isolated-process probe. Its JSON
result records the runtime and engine identity, source bytes, case count,
baseline allocated/used memory, final allocated/used memory, process peak
allocated/used memory, and whether temporary artifacts were retained. The
report describes one measured file and environment only; it is not a benchmark
claim for other datasets or hosts.

CI smoke-tests the probe's execution and JSON schema with a small official
fixture. CI intentionally applies no peak-memory threshold because allocator,
PHP build, and extension differences make such a threshold fragile. Performance
regression work should compare saved JSON reports produced with the same fixture
and runtime image.
