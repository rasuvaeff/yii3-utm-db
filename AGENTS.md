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

No PHP/Composer on the host — run in Docker via the `composer:2` image. The
package depends on the unpublished core through a path repository, so mount the
**monorepo root**, not the package directory:

```bash
docker run --rm -v "/home/rasuvaeff/projects/rasuvaeff":/repo -w /repo/yii3-utm-db \
  composer:2 sh -c 'git config --global --add safe.directory "*"; composer build'
docker run --rm -v "/home/rasuvaeff/projects/rasuvaeff":/repo -w /repo/yii3-utm-db \
  composer:2 vendor/bin/testo --suite=Integration
```

**Before publishing**, drop the local `repositories` entry and
`minimum-stability`/`prefer-stable` from `composer.json` — they exist only so
the package resolves against the unreleased core.

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
- **Timestamps are fixed-width UTC strings** (`Y-m-d H:i:s.u`, column width 30)
  because the canonical order is lexicographic on every driver.
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
