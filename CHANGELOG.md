# Changelog

All notable changes to **dpress** are documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/).

---

## [0.2.0] &ndash; 2026-08-04

The two factories that make the CMS extensible. Both are in Phase 0 rather than alongside the plugin system, because a query built with `new Query(...)` inside a service, or a form rendered as hand-written HTML, is permanently closed to extension — neither can be retrofitted without rewriting every screen.

### Added
- **`QueryFactory`** — every query is built by name and handed to its subscribers before it is returned, so a plugin can attach conditions, joins and ordering to a query it did not write. Emits a scoped `query.<name>:created` and a generic `query:created`.
- **`FormFactory`** and **`DpressForm`** — the same for forms. The factory emits `form.<name>:created` and the generic `form:created`; `DpressForm` emits `form.<name>:validated` from the framework's `afterValidate()` hook, and its `handle()` wraps the controller's work in `form.<name>:before_process` / `:after_process`.
- `DpressServices::registerWeb()` — the request, the session and the form factory, kept out of `register()` so a CLI command never touches `Session`
- `DpressException`

### Notes
- Both factories emit a scoped **and** a generic event on purpose: `EventService` matches names exactly with no wildcards, so a generic-only event would wake every subscriber on every form and every query.
- **Plugins can narrow a query but never widen it.** `Query` has no `removeCondition()`, conditions are appended, and the query builder joins them with `AND` — so a subscriber cannot strip the published-status filter off a public listing. This holds only if the registered builder adds its own security-critical conditions rather than leaving them to the caller.
- Form and query names are snake_case with no dots, so they slot cleanly into one event-namespace segment.
- Requires dynart/micro-entities 0.4.0 for `Query::nextParamName()` and the bound-variable collision check.

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
