# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Fixed

- `DbUtmAttributionRepository::purgeOlderThan()` deletes in batches by primary
  key instead of one unbounded statement. The first retention sweep of a table
  nothing else trims removed months of rows in a single transaction: undo/WAL
  growth, replication lag, and on MySQL next-key locks across the whole
  `recorded_at` range. The batch size is the new optional `purgeBatchSize`
  constructor argument, defaulting to
  `DbUtmAttributionRepository::DEFAULT_PURGE_BATCH_SIZE` (1000).
- A malformed row no longer takes the rest of the entity down with it.
  `findByEntity()` skips it and returns the readable rows, and
  `findFirst()`/`findLast()` step over a malformed edge row, scanning at most
  `UtmAttributionRepository::MAX_LIMIT` rows. Previously one foreign row hid
  the entire attribution history of an entity — including from the operator
  who had to delete it. A page can therefore come back shorter than `$limit`;
  `$offset` and the counts count rows, not readable records.
- `MalformedRow` now names the offending row: `MalformedRow::$rowId` carries
  the primary key and the message ends with `(row id N)`, so a skipped row can
  be looked up instead of only its column being named.
- `purgeOlderThan()` stops when a batch deletes nothing instead of re-selecting
  the same ids forever (a concurrent purge, or a driver-side veto on the rows).

### Added

- `DbUtmAttributionRepository` takes an optional PSR-3 `logger` and reports
  every skipped row at `error` level with its id and the reason; the default is
  a `NullLogger`. `config/di.php` passes the application logger when one is
  bound, and resolves without it when none is.

### Documentation

- README, `README.ru.md`, `llms.txt` and `AGENTS.md` state the data contract
  the row mapper enforces: `ClickIds::KNOWN_KEYS` is append-only with a frozen
  order, value normalisation and `TIME_FORMAT` are frozen, and changing any of
  them requires a migration rather than a minor release.

## 1.0.0 — 2026-08-03

- Initial release.
