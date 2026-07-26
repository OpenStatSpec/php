# OpenStatSpec PHP

`openstatspec/php` is the PHP reference adapter for the [OpenStatSpec specification](https://github.com/OpenStatSpec/specification).

The current implemented profile imports an unencrypted SPSS `.sav` dataset into SQLite as one source-faithful wide data table plus a metadata catalogue. It reconstructs a typed php-spss V3 `Dataset` from that catalogue for SAV export.

## Status

This is an early reference implementation, not a release-ready full-fidelity converter.

- **Implemented and tested with SQLite:** unencrypted SAV import and export, one data table per dataset, ordered cases, source variable names and physical-column mapping, value labels, ordinary user-missing values, formats, string widths, file labels, document records, and selected display metadata in the catalogue.
- **Explicitly rejected:** ZSAV, non-SAV inputs and outputs, and non-SQLite PDO connections. Rejections occur before the adapter changes the target database or writes a target file.
- **Declared but not live-server tested:** MySQL/MariaDB and PostgreSQL capability profiles. They describe identifiers, SQL types and wide-table limits; they do not yet implement imports or exports.
- **Current V3 integration boundary:** the adapter uses php-spss V3 typed `Dataset` and `VariableMetadata` objects end to end for its implemented SQLite/SAV slice. It preserves values, labels, all supported user-missing rule shapes, file labels, documents, formats and display metadata. ZSAV, file/variable attributes, variable sets, multiple-response sets, variable roles and non-SQLite conversion remain unimplemented and are not claimed.

An export diagnostic is information about a known loss boundary; the current PHP API still writes a SAV file when the transition bridge can do so. Consumers that require lossless output must inspect `SpssExportResult::$diagnostics` and reject a non-empty result themselves.

## Requirements

- PHP 8.4.1 or later
- PHP PDO; the current implemented profile also needs the SQLite PDO driver
- `tiamo/spss` 3.x, installed automatically by Composer from the maintained php-spss V3 repository. Its engine requirements are `ext-bcmath`, `ext-mbstring`, and `ext-zlib`.

## Current API
```php
use OpenStatSpec\Spss\SpssAdapter;

$adapter = new SpssAdapter($pdo); // SQLite PDO
$adapter->import('/data/survey.sav', 'survey_2026');
$result = $adapter->export('survey_2026', '/data/survey-export.sav');

if ($result->diagnostics !== []) {
    // The file was written, but the result identifies known fidelity limits.
}
```

`import()` currently returns `void`; `export()` returns `SpssExportResult` with the dataset name, target path, case count and fidelity diagnostics.

## Architecture

- `src/Core` — specification identities, validation and explicit diagnostics.
- `src/Sql` — PDO-backed profile boundary; SQLite is implemented, while MySQL/MariaDB and PostgreSQL are capability declarations only.
- `src/Spss` — SAV-only import/export adapter boundary and the internal external-engine bridge.

The package implements the specification; it does not define it. See [docs/architecture.md](docs/architecture.md) for the strict relational contract.

## Development

```bash
composer install
composer check
```

`composer check` is the required local verification gate before every commit or push. It validates Composer configuration, checks PHP syntax, checks coding style, runs PHPStan static analysis, and runs the test suite. To apply safe code-style fixes locally, run `composer fix` and then run `composer check` again.

The regular test suite uses SQLite in memory; no database service or Docker setup is required. php-spss V3 is installed through Composer, and the suite covers a typed engine SAV write/read round trip plus the SQLite catalogue round trip.

## Contributing

This is the PHP implementation of OpenStatSpec; the normative model lives in the [specification repository](https://github.com/OpenStatSpec/specification).

Contributions are welcome for strict-scope adapters, SQL dialect profiles, SAV/ZSAV fixtures, conformance tests, and documentation. New work must preserve the source-faithful wide-table contract and must report unsupported conversions explicitly.

## Framework use

The package is framework-neutral and has no Yii2 or Laravel dependency. A consuming application may pass its own PDO connection to `SpssAdapter`. SQLite is the only current end-to-end profile; using a Yii2, Laravel, MySQL/MariaDB or PostgreSQL application connection is not yet supported for conversion.

## SPSS engine

The selected engine is [TonisOrmisson/php-spss](https://github.com/TonisOrmisson/php-spss), consumed as the official Composer dependency `tiamo/spss` version 3.x through its V3 source repository. It is not maintained by OpenStatSpec.

`PhpSpssEngine` is a typed V3 `Dataset` boundary. The adapter deliberately continues to reject ZSAV, and it does not yet map attributes, variable sets, multiple-response sets or roles into the OpenStatSpec catalogue.

## CI feedback loop
Treat a failing CI run as a development task: diagnose the cause, make the focused fix, run `composer check` locally, then commit and push the correction. Do not merely report a failure.
