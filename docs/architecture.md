# Architecture

## Purpose

This package is a reference implementation of OpenStatSpec. The [specification repository](https://github.com/OpenStatSpec/specification) is authoritative for the data model and conformance rules.

## Strict source-faithful contract

For one imported SPSS source dataset:

1. One source dataset maps to exactly one dedicated SQL data table.
2. One SPSS case maps to exactly one SQL row.
3. One SPSS variable maps to exactly one physical SQL column, preserving source order.
4. A reserved technical `__case_ordinal` column preserves source order and is not exported as an SPSS variable.
5. The SQLite catalogue currently records dataset labels and documents; variable names, labels, storage kind, source width and print format; value labels; user-missing rules; and measurement level, display width and alignment.

The adapter must not create EAV/cell tables, long views, chunked tables, reshaped data, automatic harmonisation, or inferred respondent keys. Unsupported source features and target capabilities produce explicit diagnostics.

## Implemented profile

The only end-to-end profile is SQLite with unencrypted SAV files.

- `SpssAdapter::import(string $sourcePath, string $datasetName): void` accepts only `.sav`, normalises the external engine's source dictionary, and creates a `dataset_<normalised-name>` table plus its catalogue entries in one transaction.
- `SpssAdapter::export(string $datasetName, string $targetPath): SpssExportResult` accepts only a `.sav` target. It orders cases by `__case_ordinal`, reconstructs a typed php-spss V3 `Dataset`, writes through the external engine, and returns fidelity diagnostics.
- The typed slice preserves values, labels, all supported user-missing rules (including range-plus-value), file labels, documents, formats and measurement/display metadata.
- ZSAV is rejected before reading or writing. No claim is made about compressed SPSS data.
- SQLite strings are stored as non-null `TEXT`; numeric system-missing values are stored as `NULL`.

File and variable attributes, variable sets, multiple-response sets and variable roles are not yet catalogued by this adapter. They are deliberately outside this implementation slice and must not be represented as preserved output.

## SQL capability profiles

`SqliteProfile`, `MySqlProfile`, and `PostgreSqlProfile` define driver names, identifier quoting, numeric/text types, identifier limits, and maximum wide-table variable counts.

Only SQLite has an importer and exporter, and only SQLite is exercised through a PDO connection. MySQL/MariaDB and PostgreSQL profiles are declarations of planned capabilities, not supported conversion targets.

## Package layers

### Core

Core contains diagnostic codes, fidelity diagnostics and explicit unsupported-operation errors.

### SQL

SQL owns the supplied PDO connection, profile capability checks, the SQLite wide-table importer/exporter, and the SQLite catalogue.

### SPSS

SPSS owns SAV-only format gating, normalisation, and the bridge to the selected external engine. `SpssEngine` is an internal bridge, not a stable public extension API.

## External engine

The selected engine is [TonisOrmisson/php-spss](https://github.com/TonisOrmisson/php-spss), declared as the Composer dependency `tiamo/spss` 3.x. `PhpSpssEngine` reports an `external_engine_unavailable` diagnostic when its compatible reader or writer class is absent.

The regular suite exercises the typed V3 engine with a SAV write/read round trip. It does not claim ZSAV or unimplemented catalogue metadata support.

## Framework boundary

This package remains framework-neutral and does not require Yii2 or Laravel. Applications supply PDO directly. A future framework-specific package may integrate a framework connection or CLI, but must call this package rather than reimplement the mapping.
