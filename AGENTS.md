# AGENTS.md — yii3-utm-db

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/yii3-utm-db` stores the attribution journal of
`rasuvaeff/yii3-utm` in any database `yiisoft/db` supports. Namespace
`Rasuvaeff\Yii3UtmDb`. It implements `UtmAttributionRepository`, ships the
migration, a row mapper, a typed table-name value object and the `utm:purge`
console command.

Design decisions and their reasons live in
`../yii3-package-plans/yii3-utm-plan.md`; read it before extending the API.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build` **and** `vendor/bin/testo --suite=Integration`. The unit
   suite alone does not touch the schema.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **This package is the one binder of `UtmAttributionRepository`.** The core
   leaves that key unbound on purpose. Binding it in two places makes
   `yiisoft/config` throw `Duplicate key while building "di"`.
4. **Preserve the public contract.** Update README + README.ru + tests with any
   API or schema change; a shipped migration is never edited in place — add a
   new one.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image, from
the package directory. The core is resolved from Packagist
(`rasuvaeff/yii3-utm: ^1.0`) — a test here can only assert core behaviour that
is already **released**; a fix that spans both packages needs its cross-package
assertion in the core's own suite.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 vendor/bin/testo --suite=Integration
```

Or with Make (build only — the Integration suite has no make target):

```bash
make build
```

## Invariants & gotchas

- **`'bigprimarykey'` is not a type token.** The real one is `bigpk`, and this
  package uses `ColumnBuilder::bigPrimaryKey()` instead. SQLite accepts an
  unknown type silently: the column ends up without autoincrement and every
  `id` reads back as `NULL`, so the mapper — and every ordering guarantee —
  breaks at runtime rather than at migration time.
- **Ordering is `recorded_at, id`, both server-assigned.** `occurred_at` is what
  the client claimed and must never appear in an `ORDER BY`.
- **`append()` must stay race-safe**: a no-op upsert plus `IntegrityException`
  handling. "Check, then insert" leaves a window where two requests both write.
  Only a duplicate-key conflict may be swallowed as "already recorded" — the
  classification lives in `IntegrityError`; a recognised foreign-key / CHECK /
  NOT NULL violation must surface, and unrecognised drivers keep the legacy
  tolerant path.
- **Timestamps are fixed-width UTC strings** (`Y-m-d H:i:s.u`, column width 30)
  because the canonical order is lexicographic on every driver.
- **The canonical form is a data contract, frozen for the lifetime of this
  schema.** `UtmAttributionRowMapper::toRecord()` recomputes the fingerprint
  and the dedupe key and requires `click_ids`, `landing_page` and the `utm_*`
  values to be canonical, so every one of these is *stored* semantics, not an
  implementation detail: `ClickIds::KNOWN_KEYS` (append-only, order frozen —
  the order is part of the canonical JSON), `ClickIds::MAX_VALUE_LENGTH` and
  its value pattern, `UtmParameters::normalize()` and its
  `MAX_VALUE_LENGTH`, and `TIME_FORMAT`. Changing any of them turns
  already-stored rows into `MalformedRow`s and needs a migration — a
  `TIME_FORMAT` change rewrites `occurred_at` and `recorded_at`, a `ClickIds`
  change rewrites `click_ids`, and any change to the normalised values
  recomputes `fingerprint` and `dedupe_key`. It is never a minor release.
- **A malformed row is skipped on read and must name itself.** Strictness in
  the mapper is right; strictness *fatal on read* is not — one foreign row
  would hide the entire history of an entity, including from the operator who
  has to delete it. `map()` catches `MalformedRow` and returns it to the
  caller; each call reports its skips through `reportSkipped()` — the first
  skip with row id, reason and exception, every further skip of the same call
  counted into one summary line (a bulk import of foreign rows must not fill
  the error channel with duplicates of one cause). `findFirst()`/`findLast()`
  widen from `limit(1)` to a scan bounded by `MAX_LIMIT` only after the row
  they fetched turns out to be broken. Consequences that belong in every doc
  touching reads: a page can be **shorter than `$limit`**, `$offset` counts
  rows and the counts count rows. `toRecord()` reads the id first and re-throws
  every later `MalformedRow` through `forRow()`; a message naming only the
  column leaves nothing to look the row up by.
- **`MalformedRow` keeps the inherited `\RuntimeException` signature.** It is
  `@api`; `$rowId` is appended after `$message`, `$code`, `$previous`, and
  `forRow()` carries all three over.
- **`purgeOlderThan()` deletes in batches by primary key.** `DELETE ... LIMIT`
  is not portable across the drivers `yiisoft/db` supports, so the loop selects
  a bounded set of ids and deletes those; a non-positive `purgeBatchSize` is
  rejected in the constructor (`LIMIT 0` selects nothing and would silently
  turn the sweep into a no-op). Batching is invisible in the end
  state — the rows are gone either way — so the integration test counts the
  statements through a `ProfilerInterface`; asserting only on the row count
  would pass against an unbounded delete. The loop also stops when a batch
  deletes nothing: the same ids would be selected forever otherwise. The test
  for that installs a SQLite `BEFORE DELETE … RAISE(IGNORE)` trigger through
  the raw PDO handle — `PDO::prepare()` cannot carry a trigger body, it stops
  at the first `;` inside `BEGIN … END`.
- **The table name comes only from `UtmAttributionTableName`.** Migrations get
  it through `Injector::make()`, which resolves by type — a scalar constructor
  argument silently would not be configurable.
- **`setSourceNamespaces()` does not work** for this package (ER-044 in
  `docs/evolved-rules.md`): `Rasuvaeff\Yii3Utm\` is a string prefix of
  `Rasuvaeff\Yii3UtmDb\`, so discovery walks into the core package and finds
  nothing, silently. Document and test only the `Injector::make()` + `up()`
  path.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types, named arguments; psalm annotations written up front.
- **PHP 8.3 is supported**: `new X()->method()` is 8.4+ syntax and must not
  appear even though the local image runs 8.5.
- **CI workflows are SHA-pinned** with `permissions: { contents: read }` and
  `persist-credentials: false`; verify with `zizmor --persona=auditor .github/`.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build` and the Integration suite; paste the output.
