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

### The HTTP layer

`DpressWebApp` wires a middleware order that makes cookie-based login work:

| Priority | Middleware | Does |
|---|---|---|
| 40 | `JwtCookieReader` | access-token cookie → `Authorization` header |
| 45 | `TokenRefresher` | no header + refresh cookie → refresh, set new cookies, set header |
| 50 | `JwtValidator` | decodes whatever ended up in the header |

**`TokenRefresher` never decodes anything.** `AuthCookies` sets the access cookie to expire ~30s *before* its token does, so the browser stops sending it while it would still be valid — a request from a logged-in user whose token aged out simply arrives with no `Authorization` header and a refresh cookie. Without this, a 15-minute access TTL means a 401 error page every 15 minutes.

Refresh tokens **rotate**: `AuthService::refresh()` revokes the old one and issues a new one, so a stolen token is usable at most once. A spent or revoked token makes `TokenRefresher` clear the cookies and carry on anonymously rather than throw — a stale cookie must never lock somebody out of the site.

Controllers extend `AbstractController` and stay thin: read the request, call a service, render. `#[Authorize]` with no permission means "any logged-in user". Anonymous is the default — `JwtAuth::checkAuthorization()` only intervenes when a class or method declares an authorization.

`/logout` is **POST only**, so a link planted on another page cannot log a visitor out.

The routing note that costs an hour if you don't know it: **`Router::currentRoute()` reads the path from a request *parameter*** (`route` by default), not from `REQUEST_URI`. The rewrite has to supply it — `RewriteRule ^(.*)$ index.php?route=/$1 [QSA,L]`. `public/router.php` does the same for PHP's built-in server, which has no rewriting.

### Mail

A mail is **two templates**: `<template>.phtml` for the HTML body and an optional `<template>.txt.phtml` for the plain text alternative. Both go through `ViewInterface`, so a theme overrides a mail template exactly the way it overrides a page template — same lookup, same namespaces, and each body can be overridden independently.

`AbstractMailer` does the rendering; a subclass implements one method, `deliver(Mail $mail): bool`. Shipped: `LogMailer` (the default — writes to the log, so a password-reset flow can be walked through without an SMTP server and the reset URL is right there to click) and `NativeMailer` (PHP `mail()`, `multipart/alternative` when a text body exists).

`mail.mailer` picks one by short name (`log`, `native`) or by class name, so an application can plug in PHPMailer or Symfony Mailer with a subclass and one config line.

The send signature is `send($name, $email, $subject, $template, $variables)`. `create()` renders without sending, which is what `dpress mail:test -render` uses.

**In `multipart/alternative` the text part must come first** — a client displays the *last* part it can render, so putting HTML first would show everyone the plain text version.

`mail:before_send` carries the rendered `Mail` and fires before the transport sees it, so a subscriber can still change it.

### Schema

`SchemaService` sits between the migration runner and whatever drives it — the CLI now, a web installer later.

**`install` is idempotent**: it applies whatever is pending rather than refusing when the migration history table exists. A migration that fails part way leaves exactly that state, and refusing would strand the site with no way forward.

`Revision` (from the entities library) is created by `Migration\CreateRevisionTable`. Because it is a library entity, the application's namespace scan does not reach it, so the migration calls `EntityManager::registerEntity()` explicitly.

## Conventions

- **Events**: `<namespace>[.<sub>]:<event_name>` — a single colon, snake_case, past-participle verbs. ORM-level events carry an `entity.` prefix so they cannot collide with service-level ones. See §8 of the plan.
- **Permissions** are plain strings (`post.create`), no table.
- **Forms** must be rendered via `$form->fetch()`, never hand-written `<input>` tags, or plugin-added fields will not appear.
- **All state changes go through a service method**, and every service method emits before/after events. A controller writing an entity directly is invisible to plugins forever.
