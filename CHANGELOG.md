# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- Initial storage adapter for rasuvaeff/yii3-utm: portable `yiisoft/db`
  repository with a race-safe append, canonical server-side ordering,
  retention and per-entity erasure.
- Migration with a unique index on the derived dedupe key; typed table-name
  value object shared by the migration and the repository.
- Strict row mapper: a row that does not match the schema raises
  `MalformedRow` instead of being coerced.
- `utm:purge` console command.
