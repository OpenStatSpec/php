# OpenStatSpec PHP

`openstatspec/php` is the PHP reference adapter for the [OpenStatSpec specification](https://github.com/OpenStatSpec/specification).

It will import an SPSS `.sav` or `.zsav` dataset into a connected SQL database as one source-faithful wide data table plus the specification's separate metadata tables. It will also export a conforming dataset from that database back to SPSS.

## Status

This is an initial scaffold. It exposes the intended public API but does **not** yet parse or write SAV/ZSAV files. Calling a conversion method fails explicitly with `UnsupportedOperation`; it never silently transforms, reshapes, truncates, or drops data.

## Requirements

- PHP 8.3 or later
- A PDO connection for the selected SQL dialect

## Intended API

```php
use OpenStatSpec\Spss\SpssAdapter;

$adapter = new SpssAdapter($pdo);
$adapter->import('/data/survey.sav', 'survey_2026');
$adapter->export('survey_2026', '/data/survey-export.sav');
```

The current methods deliberately throw until an SPSS reader/writer and a supported SQL dialect profile are implemented.

## Architecture

- `src/Core` — specification identities, validation and explicit diagnostics.
- `src/Sql` — PDO-backed connection boundary and future dialect profiles.
- `src/Spss` — SAV/ZSAV import and export adapter boundary.

The package implements the specification; it does not define it. See [docs/architecture.md](docs/architecture.md) for the strict relational contract.

## Development

```bash
composer install
composer check
```

`composer check` is the required local verification gate before every commit or push. It validates Composer configuration, checks PHP syntax, checks coding style, runs PHPStan static analysis, and runs the test suite. To apply safe code-style fixes locally, run `composer fix` and then run `composer check` again.

Tests use SQLite in memory only where a database handle is needed; no database service or Docker setup is required for this scaffold.

## Contributing

This is the PHP implementation of OpenStatSpec; the normative model lives in the [specification repository](https://github.com/OpenStatSpec/specification).

Contributions are welcome for strict-scope adapters, SQL dialect profiles, SAV/ZSAV fixtures, conformance tests, and documentation. New work must preserve the source-faithful wide-table contract and must report unsupported conversions explicitly.

## Framework extensions

The framework-neutral core accepts PDO and has no Yii2 or Laravel dependency. A future separate Yii2 package can depend on this package and `yiisoft/yii2`, then provide `yii\\db\\Connection` integration, migrations, and console commands without changing this core package.

## First usable milestone

The fastest integration path is framework-neutral PDO: pass the existing application PDO connection directly to `SpssAdapter`. The first vertical slice will create the strict wide data table and metadata catalog, run preflight capability checks, and return explicit diagnostics before SAV/ZSAV parsing and export are completed.

This allows an existing Yii2 application to use its own database connection without adding Yii2 to this package. Framework-specific adapters remain deferred.
