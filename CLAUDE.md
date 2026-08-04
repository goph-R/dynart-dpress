# CLAUDE.md

## Project Overview

**dpress** is a markdown-based CMS built on [dynart-micro](../dynart-micro) (framework) and [dynart-micro-entities](../dynart-micro-entities) (ORM). PHP 8.0+, namespace `Dynart\Dpress`, PSR-4 from `src/`. Both dependencies are symlinked through Composer path repositories — treat all three folders as one codebase.

The overall design lives in `../dynart-dpress-plan.md`. Read it before making structural decisions; it records *why* things are the way they are (single content table, single language per site, permanent audit history, the event naming convention).

Status: the package skeleton and the CLI exist. The content model, web front end and admin UI do not.

## Related repositories

- `../dynart-dpress-test/` — the PHPUnit suite, a separate repo symlinking this one
- `../dynart-dpress-app/` — a runnable site for development, with a real `dpress.ini` and database

## Running the CLI

```bash
# from dynart-dpress-app/
vendor/bin/dpress help
vendor/bin/dpress install
vendor/bin/dpress migrate:status

# from anywhere
php ../dynart-dpress/bin/dpress.php migrate:status -config path/to/dpress.ini
```

## Running Tests

```bash
# from ../dynart-dpress-test/
php vendor/bin/phpunit --stderr
```

## Architecture

### Bootstrapping

`CliApp` and `WebApp` have no common ancestor in the framework, so the wiring both need lives in **`DpressServices`** rather than in a base class:

- `register()` — every DI binding, including the MariaDB implementations bound to `Database` / `QueryBuilder`
- `addMigrations()` — the core migration list

`Micro` auto-wires constructors by reflection but only for classes it has been told about, so **every** class resolved through the container must be registered — even where the interface and the implementation are the same class.

### The CLI

`bin/dpress` (bash) and `bin/dpress.bat` (batch, deliberately not PowerShell) both delegate to `bin/dpress.php`. The launchers only resolve their own directory; all logic is in PHP so there is one implementation.

`bin/autoload.php` finds the Composer autoloader across the three ways dpress can be installed: checked out standalone, installed into a site's `vendor/`, or symlinked through a path repository.

**Config discovery**: `-config <path>` wins; otherwise the tree is walked upward from the working directory looking for `dpress.ini`.

**`DpressCliApp::COMMANDS`** is the single source of truth for the command table — callable, description and whether the command needs a config. `dpress help` renders it, and `bin/dpress.php` consults `commandNeedsConfig()` before booting. Keeping it as data avoids a second registry drifting out of sync, and means an *unknown* command reaches the app and gets the help rather than a config error it never asked for.

Commands must return `int` (or `string`) — `CliApp::process()` passes the return value straight to `finish()`, and `null` is a TypeError.

### The two factories

`FormFactory` (write path) and `QueryFactory` (read path) are what make the CMS extensible, and the rule for both is the same: **nothing builds a `Form` or a `Query` with `new`.** A hand-built one is invisible to plugins forever. For forms this extends into the templates — render with `$form->fetch()`, never hand-written `<input>` tags, or a plugin-added field will not appear.

Both emit a **scoped and a generic** event. `EventService` matches names exactly with no wildcard support, so a generic-only event would wake every subscriber on every form and every query; the generic one exists for the genuinely cross-cutting cases (a captcha on all forms, access scoping on all queries).

| | Form | Query |
|---|---|---|
| Builder signature | `fn(DpressForm $form, array $context): void` — mutates | `fn(array $context): Query` — returns |
| Scoped events | `form.<name>:created`, `:validated`, `:before_process`, `:after_process` | `query.<name>:created` |
| Generic event | `form:created` | `query:created` |

The builder signatures differ because `Form` needs its name at construction (it is part of the CSRF session key and of every input name) while `Query` needs its source table at construction. Neither has a setter for those.

**Plugins can narrow a query but never widen it** — this falls out of the `Query` API rather than being enforced: no `removeCondition()`, conditions are appended, and `QueryBuilder::where()` joins them with `AND`. A subscriber cannot strip `status = 'published'` off a public listing. It only holds if the registered builder adds its own security-critical conditions rather than leaving them to the caller.

**Bound parameter names**: a subscriber adding a condition to somebody else's query must use `Query::nextParamName('base')` to get a free placeholder. Reusing a name that is already bound to a different value throws (entities 0.4.0) rather than silently corrupting the other condition.

### Identity

`User`, `Role`, `UserRole`, `RolePermission` are audited; `RefreshToken` and `UserToken` are not — they hold credentials and are short-lived, and auditing them would copy secrets into a table that is never deleted from.

**The audited relation tables carry no `ON DELETE CASCADE`, on purpose.** A cascade happens inside the database, so no entity event fires and no audit row is written — the history would show a role grant simply *gone*. `UserService::delete()` and `RoleService::delete()` remove those rows through the entity manager first. If you add another audited relation table, do the same.

**The admin role holds every permission implicitly** (`DpressUser::hasPermission()` short-circuits on it), so it is seeded with none and a permission invented later by a plugin needs no retroactive grant. It is also seeded `removable = false`, and `user:role -revoke` refuses to take it from the last administrator.

**Permissions are plain strings** — `Permissions::add()` is all a plugin needs; there is no lookup table to migrate.

Access tokens carry the user's roles and permissions in the payload, so an authorized request costs no database query — but a role change only lands on the next refresh, which is why the access TTL is 15 minutes. `AuthService::refresh()` revokes the old refresh token and issues a new one, so a stolen token is usable at most once.

Login failures are deliberately indistinguishable: a wrong password, a blocked account and a pending account all produce the same message, and `createPasswordResetToken()` returns `null` for an unknown address rather than throwing. Neither should become a way of finding out who has an account.

### Schema

`SchemaService` sits between the migration runner and whatever drives it — the CLI now, a web installer later.

**`install` is idempotent**: it applies whatever is pending rather than refusing when the migration history table exists. A migration that fails part way leaves exactly that state, and refusing would strand the site with no way forward.

`Revision` (from the entities library) is created by `Migration\CreateRevisionTable`. Because it is a library entity, the application's namespace scan does not reach it, so the migration calls `EntityManager::registerEntity()` explicitly.

## Conventions

- **Events**: `<namespace>[.<sub>]:<event_name>` — a single colon, snake_case, past-participle verbs. ORM-level events carry an `entity.` prefix so they cannot collide with service-level ones. See §8 of the plan.
- **Permissions** are plain strings (`post.create`), no table.
- **Forms** must be rendered via `$form->fetch()`, never hand-written `<input>` tags, or plugin-added fields will not appear.
- **All state changes go through a service method**, and every service method emits before/after events. A controller writing an entity directly is invisible to plugins forever.
