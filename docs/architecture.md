# Architecture

## Purpose

This package is a reference implementation of OpenStatSpec. The specification repository is authoritative for the data model and conformance rules.

## Strict source-faithful contract

For one imported SPSS source dataset:

1. One source dataset maps to exactly one dedicated SQL data table.
2. One SPSS case maps to exactly one SQL row.
3. One SPSS variable maps to exactly one physical SQL column, preserving source order.
4. A reserved technical case-ordinal column is added for source ordering and is not exported as an SPSS variable.
5. Separate metadata tables preserve dictionary semantics such as labels, formats, user-missing rules, attributes, documents, variable sets and multiple-response sets.

The adapter must not create EAV/cell tables, long views, chunked tables, reshaped data, automatic harmonisation, or inferred respondent keys. An unsupported source feature or target capability must produce an explicit diagnostic.

## Package layers

### Core

Core contains stable names, validation contracts and machine-readable diagnostics. It has no SPSS parser or SQL-dialect behaviour.

### SQL

SQL owns the supplied PDO connection, profile capability checks and eventual creation of the one wide data table plus metadata catalog. Dialect-specific behaviour belongs below this layer.

### SPSS

SPSS owns SAV/ZSAV reading and writing and maps their semantics through Core and SQL. Until real readers/writers exist, it must fail explicitly.

## Planned API

- `SpssAdapter::import(string $sourcePath, string $datasetName): void`
- `SpssAdapter::export(string $datasetName, string $targetPath): void`

Both operations require a PDO connection at adapter construction.

## Framework extension boundary

This package remains framework-neutral and depends only on PHP/PDO at its database boundary. It must not require Yii2 or Laravel.

A future Yii2 integration belongs in a separate package. That package may depend on this PHP core and `yiisoft/yii2`, then adapt `yii\\db\\Connection`, offer migrations, and expose console commands. The integration must call this package's public API rather than embed a second implementation of the standard.
