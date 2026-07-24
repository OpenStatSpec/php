# OpenStatSpec PHP

`openstatspec/php` is the PHP reference adapter for the [OpenStatSpec specification](https://github.com/OpenStatSpec/specification).

The current implemented profile imports an unencrypted SPSS `.sav` dataset into SQLite as one source-faithful wide data table plus a metadata catalogue. It can reconstruct a `.sav` writer payload from that SQLite catalogue and write it through a compatible external SPSS engine.

## Status

This is an early reference implementation, not a release-ready full-fidelity converter.

- **Implemented and tested with SQLite:** unencrypted SAV import and export, one data table per dataset, ordered cases, source variable names and physical-column mapping, value labels, ordinary user-missing values, formats, string widths, file labels, document records, and selected display metadata in the catalogue.
- **Explicitly rejected:** ZSAV, non-SAV inputs and outputs, and non-SQLite PDO connections. Rejections occur before the adapter changes the target database or writes a target file.
- **Declared but not live-server tested:** MySQL/MariaDB and PostgreSQL capability profiles. They describe identifiers, SQL types and wide-table limits; they do not yet implement imports or exports.
- **Known export boundaries:** the selected writer does not restore file labels, documents, display metadata, or range-plus-discrete user-missing rules; these produce fidelity diagnostics in the export result. A string variable or value wider than 255 bytes stops export before a file is written.

An export diagnostic is information about a known loss boundary; the current PHP API still writes the SAV file when the external writer can do so. Consumers that require lossless output must inspect `SpssExportResult::$diagnostics` and reject a non-empty result themselves.

## Requirements

- PHP 8.3 or later
- PHP PDO with the SQLite driver for the current import/export profile
- A compatible SAV engine for real file conversion. The package does not currently declare that engine as a Composer dependency.

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

The regular test suite uses SQLite in memory; no database service or Docker setup is required. The optional real-engine integration test is skipped unless `OPENSTATSPEC_PHP_SPSS_PATH` points to a compatible checkout of [TonisOrmisson/php-spss](https://github.com/TonisOrmisson/php-spss):

```bash
OPENSTATSPEC_PHP_SPSS_PATH=/path/to/php-spss composer test
```

That optional test imports the engine's fixture into SQLite, exports it, and reads the written SAV file back.

## Contributing

This is the PHP implementation of OpenStatSpec; the normative model lives in the [specification repository](https://github.com/OpenStatSpec/specification).

Contributions are welcome for strict-scope adapters, SQL dialect profiles, SAV/ZSAV fixtures, conformance tests, and documentation. New work must preserve the source-faithful wide-table contract and must report unsupported conversions explicitly.

## Framework use

The package is framework-neutral and has no Yii2 or Laravel dependency. A consuming application may pass its own PDO connection to `SpssAdapter`. SQLite is the only current end-to-end profile; using a Yii2, Laravel, MySQL/MariaDB or PostgreSQL application connection is not yet supported for conversion.

## SPSS engine

The selected external SAV engine is [TonisOrmisson/php-spss](https://github.com/TonisOrmisson/php-spss). It is intentionally not a Composer dependency because its published Composer identity remains `tiamo/spss` and OpenStatSpec must not add a VCS-only public dependency.

`PhpSpssEngine` detects whether compatible reader and writer classes are available at runtime. If the needed class is missing, the operation stops with an `external_engine_unavailable` diagnostic. `SpssEngine` is an internal boundary around that engine, not a stable public extension API.

The adapter does not claim ZSAV capability because the selected engine's ZLIB path is not verified. It rejects `.zsav` before attempting to read it.

## CI feedback loop

Treat a failing CI run as a development task: diagnose the cause, make the focused fix, run `composer check` locally, then commit and push the correction. Do not merely report a failure.
