# Changelog

All notable changes to **dpress** are documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/).

---

## [0.3.0] &ndash; 2026-08-04

Phase 1, part one: the identity domain and its CLI. The HTTP flows (login, registration, profile) come next.

### Added
- **Identity entities** — `User`, `Role`, `UserRole`, `RolePermission`, `RefreshToken`, `UserToken`. Password resets and email verifications share one `UserToken` table with a `type` column rather than two tables that would have to be kept in step.
- **`Permissions`** — the registry of permission strings. A plugin calling `add('myplugin.do_thing')` shows up in the role editor without a migration or a lookup table.
- **`DpressUser`** — the `JwtUserInterface` the framework's `#[Authorize]` checks against. An admin holds every permission implicitly, so a permission invented later by a plugin needs no retroactive grant.
- **`UserService`** — create, register, update, delete, password and role changes, each emitting before/after events
- **`RoleService`** — roles and their permissions, with `setPermissions()` emitting one event per actual change
- **`AuthService`** — login, logout, refresh and password reset. Issues a short-lived access token carrying the user's roles and permissions, plus a refresh token stored hashed. Refreshing revokes the old token and issues a new one, so a stolen token is usable at most once.
- **`PasswordHasher`** — passwords through `password_hash()`, and single-use tokens hashed with sha256 (they are long random values, so there is nothing to salt and a lookup has to be one indexed query)
- **`CoreQueries`** — the CMS query builders, all registered through `QueryFactory`
- **CLI** — `user:create`, `user:password`, `user:list`, `user:role`, `role:list`. `user:create` and `user:password` generate a password when none is given, so a site can be bootstrapped and a locked-out administrator can get back in without touching the database.
- `Migration\CreateIdentityTables` — the tables plus the three default roles

### Notes
- **The audited relation tables carry no `ON DELETE CASCADE`.** A cascade happens inside the database, so no entity event fires and no audit row is written — the history would show a role grant simply gone. `UserService` and `RoleService` delete those rows through the entity manager before removing the parent.
- The admin role is seeded **unremovable and with no explicit permissions**, because it holds all of them implicitly. `user:role -revoke` refuses to remove the last administrator.
- Login refuses a blocked or pending account with the same message as a wrong password, and `createPasswordResetToken()` returns null for an unknown address rather than throwing, so neither turns into a way of finding out who has an account.
- Requires dynart/micro 0.11.0 (`JwtCookieReader`, `Response` cookies) and dynart/micro-entities 0.4.1.

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
