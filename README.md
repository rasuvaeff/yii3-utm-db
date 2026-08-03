# rasuvaeff/yii3-utm-db

[![Stable Version](https://poser.pugx.org/rasuvaeff/yii3-utm-db/v)](https://packagist.org/packages/rasuvaeff/yii3-utm-db)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-utm-db/downloads)](https://packagist.org/packages/rasuvaeff/yii3-utm-db)
[![Build](https://github.com/rasuvaeff/yii3-utm-db/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-utm-db/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-utm-db/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-utm-db/actions/workflows/static-analysis.yml)
[![Psalm level](https://shepherd.dev/github/rasuvaeff/yii3-utm-db/level.svg)](https://shepherd.dev/github/rasuvaeff/yii3-utm-db)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Database storage for [rasuvaeff/yii3-utm](https://github.com/rasuvaeff/yii3-utm):
a portable `yiisoft/db` implementation of the attribution journal, with a
race-safe append, canonical server-side ordering, retention and erasure.

> Using an AI coding assistant? [llms.txt](llms.txt) is a compact API reference
> written for LLMs.

## Requirements

- PHP 8.3 – 8.5
- `ext-json`, `ext-pdo`
- `rasuvaeff/yii3-utm`, `yiisoft/db` 2.x, `yiisoft/db-migration` 2.x

## Installation

```bash
composer require rasuvaeff/yii3-utm-db
```

Installing this package is all the wiring there is: the core deliberately
leaves `UtmAttributionRepository` unbound and this package binds it. Never bind
it a second time in the application — `yiisoft/config` reports
`Duplicate key while building "di"` when two sources define one key.

## Migration

`MigrationService::setSourceNamespaces()` does **not** find migrations of this
package: `yiisoft/db-migration` matches the PSR-4 map by string prefix, and
`Rasuvaeff\Yii3Utm\` is a prefix of `Rasuvaeff\Yii3UtmDb\`, so discovery walks
into the core package and silently finds nothing. Apply the migration directly
instead:

```php
use Rasuvaeff\Yii3UtmDb\Migration\M260801000000CreateUtmAttributionTable;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;

$builder = new MigrationBuilder($db, new NullMigrationInformer());
$injector->make(M260801000000CreateUtmAttributionTable::class)->up($builder);
```

`Injector::make()` resolves the table name **by type**, so the migration and
the repository can never disagree about it.

## Schema

| Column | Type | Meaning |
|---|---|---|
| `id` | big primary key | Insert order; part of the canonical order |
| `entity_id` | `string(191)` | Whom the attribution belongs to |
| `event_id` | `string(191)` | Application-owned idempotency key |
| `interaction_type` | `string(32)` | `purchase`, `registration`, anything validated |
| `utm_source` … `utm_id` | `string(255)` | Campaign tuple, GA4 `utm_id` included |
| `click_ids` | `string(500)` | JSON object in whitelist order, `{}` when empty |
| `referrer`, `referrer_host` | `string(500)`, `string(255)` | Only the host takes part in the fingerprint |
| `landing_page` | `string(500)` | Sanitised before it ever reaches the row |
| `occurred_at` | `string(30)` | Claimed by the source; analytics only |
| `recorded_at` | `string(30)` | Server clock at insert time |
| `fingerprint`, `dedupe_key` | `string(64)` | Derived; the unique index sits on `dedupe_key` |

Indexes: `UNIQUE (dedupe_key)`, `(entity_id, recorded_at, id)`,
`(entity_id, interaction_type, recorded_at, id)`, `(recorded_at)`.

Timestamps are fixed-width UTC strings (`Y-m-d H:i:s.u`) so that the canonical
order is lexicographic on every driver. There is no `is_first_interaction`
column: first touch is whatever the server recorded first.

## Configuration

```php
// config/common/params.php
'rasuvaeff/yii3-utm-db' => [
    'table' => 'utm_attribution',
    'table_prefix' => '',
    'retentionDays' => 365,
],
```

## Retention and erasure

```bash
./yii utm:purge --older-than=P365D          # retention sweep
./yii utm:purge --older-than=P90D --dry-run
```

`--older-than` accepts a positive ISO-8601 interval such as `P90D`, `P6M` or
`PT12H`. Without the option, `retentionDays` supplies the default. `--dry-run`
reports the number of rows that would be deleted, without deleting them.

Deleting one person's data is a different operation and lives on the
repository: `deleteByEntity($entityId)`.

## Security

| Aspect | Behaviour |
|---|---|
| SQL | Every value is a bound parameter |
| Identifiers | Table and index names come only from the validated `UtmAttributionTableName` |
| Ordering | Server-assigned; a client cannot backdate itself into first touch |
| Duplicates | Prevented by the unique index plus a race-safe upsert, not by check-then-insert |
| Foreign rows | A row that does not match the canonical schema raises `MalformedRow` instead of being coerced. Every exception of this package implements `UtmDbException`, which extends the core `UtmException`. |

## Examples

Runnable scripts live in [examples/](examples/).

## Development

```bash
make build
make test
make cs-fix
make psalm
```

The Integration suite runs against in-memory SQLite:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/testo --suite=Integration
```

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
