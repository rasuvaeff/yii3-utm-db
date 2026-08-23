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
    'purgeBatchSize' => 1000,
],
```

`purgeBatchSize` is the number of rows `purgeOlderThan()` deletes per
statement; values below one are rejected in the constructor.

## Retention and erasure

```bash
./yii utm:purge --older-than=P365D          # retention sweep
./yii utm:purge --older-than=P90D --dry-run
```

`--older-than` accepts a positive ISO-8601 interval such as `P90D`, `P6M` or
`PT12H`. Without the option, `retentionDays` supplies the default. `--dry-run`
reports the number of rows that would be deleted, without deleting them.

`purgeOlderThan()` deletes in batches by primary key —
`DbUtmAttributionRepository::DEFAULT_PURGE_BATCH_SIZE` (1000) rows per
statement, configurable through the `purgeBatchSize` constructor argument or
the package parameter of the same name. An initial sweep over a table without
another trimming process can remove months of rows, and one unbounded `DELETE`
would mean one long transaction: undo/WAL growth, replication lag, and on
MySQL next-key locks across the whole `recorded_at` range.

Deleting one person's data is a different operation and lives on the
repository: `deleteByEntity($entityId)`.

## Data contract

The mapper does not trust the table: it recomputes the fingerprint and the
dedupe key from the row and rejects a mismatch, and it requires `click_ids`,
`landing_page`, the `utm_*` values and both timestamps to be in their canonical
form. That is what keeps an untrusted row out of an attribution report — and it
also means the canonical form is **data**, not an implementation detail.

Frozen for the lifetime of this schema:

| Frozen | Why |
|---|---|
| `ClickIds::KNOWN_KEYS` — append-only, order never changes | The order is part of the canonical `click_ids` JSON and of the fingerprint |
| `ClickIds::MAX_VALUE_LENGTH` and the value pattern | A different cut-off yields a different canonical JSON for the same input |
| `UtmParameters::normalize()` and `MAX_VALUE_LENGTH` | Feeds the fingerprint and the `utm_*` canonicity check |
| `UtmAttributionRowMapper::TIME_FORMAT` | Ordering is lexicographic on the stored string |

Changing any of them turns already-stored rows into `MalformedRow`s and
requires a migration — a `TIME_FORMAT` change rewrites `occurred_at` and
`recorded_at`, a `ClickIds` change rewrites `click_ids`, and any change to the
normalised values recomputes `fingerprint` and `dedupe_key`. It is never a
minor release.

### A row that fails the contract

A malformed row is **skipped, not fatal**. Making the mapper's strictness fatal
on read would let one foreign row hide the entire attribution history of an
entity, and the operator who has to remove it could not read the rest first.
So:

| Read | Behaviour |
|---|---|
| `findByEntity()` | Returns the readable rows. A page can come back **shorter than `$limit`**, and `$offset` counts rows, not records |
| `findFirst()` / `findLast()` | Step over a malformed edge row and return the first readable one in that direction, scanning at most `UtmAttributionRepository::MAX_LIMIT` rows |
| `countByEntity()` / `countOlderThan()` | Count **rows** — unreadable ones included; the gap against a shorter page is the signal |

Each call that skips rows reports them to the PSR-3 logger at `error` level —
the first skip with its id, reason and exception, every further skip of the
same call counted into one summary line:

```php
$repository = new DbUtmAttributionRepository(
    db: $db,
    clock: $clock,
    logger: $logger, // without it the report is silent
);
```

The default is a `NullLogger`, so **bind the application logger** — otherwise a
core normalisation change turns every row unreadable and the reports go
nowhere. The container wiring shipped with this package already passes the
application's `LoggerInterface` when one is bound.

`MalformedRow::$rowId` carries the primary key and the message ends with
`(row id N)`; `MalformedRow` is still thrown by `UtmAttributionRowMapper` itself
if you call the mapper directly.

## Security

| Aspect | Behaviour |
|---|---|
| SQL | Every value is a bound parameter |
| Identifiers | Table and index names come only from the validated `UtmAttributionTableName` |
| Ordering | Server-assigned; a client cannot backdate itself into first touch |
| Duplicates | Prevented by the unique index plus a race-safe upsert, not by check-then-insert; only a duplicate-key conflict is swallowed as "already recorded" — a foreign-key, CHECK or NOT NULL violation of the same table surfaces as an exception instead of counting as a handled delivery |
| Foreign rows | A row that does not match the canonical schema is never coerced into a record: the mapper raises `MalformedRow` naming the offending row id, and the repository skips the row and reports it instead of returning it. Every exception of this package implements `UtmDbException`, which extends the core `UtmException`. |

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
