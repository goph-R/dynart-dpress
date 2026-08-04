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

### Schema

`SchemaService` sits between the migration runner and whatever drives it — the CLI now, a web installer later.

**`install` is idempotent**: it applies whatever is pending rather than refusing when the migration history table exists. A migration that fails part way leaves exactly that state, and refusing would strand the site with no way forward.

`Revision` (from the entities library) is created by `Migration\CreateRevisionTable`. Because it is a library entity, the application's namespace scan does not reach it, so the migration calls `EntityManager::registerEntity()` explicitly.

## Conventions

- **Events**: `<namespace>[.<sub>]:<event_name>` — a single colon, snake_case, past-participle verbs. ORM-level events carry an `entity.` prefix so they cannot collide with service-level ones. See §8 of the plan.
- **Permissions** are plain strings (`post.create`), no table.
- **Forms** must be rendered via `$form->fetch()`, never hand-written `<input>` tags, or plugin-added fields will not appear.
- **All state changes go through a service method**, and every service method emits before/after events. A controller writing an entity directly is invisible to plugins forever.
