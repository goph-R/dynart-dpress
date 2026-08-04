# Changelog

All notable changes to **dpress** are documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/).

---

## [0.1.0] &ndash; 2026-08-04

The package skeleton and the command line tool. No content model yet.

### Added
- `dpress` command line tool with a bash launcher, a batch launcher for Windows, and a single PHP entry point both delegate to
- Config discovery: `-config <path>`, otherwise the directory tree is walked upward looking for a `dpress.ini`
- Commands: `install`, `upgrade`, `migrate:status`, `version`, `help`
- `DpressCliApp` with the command table as data, so `dpress help` and the config-requirement check read from one source
- `DpressServices` — the DI registrations and core migration list shared by every kind of dpress application
- `SchemaService` — install / upgrade / status, between the migration runner and whatever drives it
- `Migration\CreateRevisionTable` — the first migration, creating the table the auditing depends on
- `Dpress` — version and the shared constants

### Notes
- `install` is idempotent: it applies whatever is pending rather than refusing when the migration history table already exists, which is the state a failed migration leaves behind
- Requires dynart/micro 0.10.0 and dynart/micro-entities 0.3.1
