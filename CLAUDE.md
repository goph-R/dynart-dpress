# CLAUDE.md

## Project Overview

**dpress** is a markdown-based CMS built on [dynart-micro](../dynart-micro) (framework) and [dynart-micro-entities](../dynart-micro-entities) (ORM). PHP 8.0+, namespace `Dynart\Dpress`, PSR-4 from `src/`. Both dependencies are symlinked through Composer path repositories — treat all three folders as one codebase.

The overall design lives in `../dynart-dpress-plan.md`. Read it before making structural decisions; it records *why* things are the way they are (single content table, single language per site, permanent audit history, the event naming convention).

Status: phases 0–5 of the plan are done — the CLI, both factories, the mailer, the identity stack with its HTTP flows, the content model with its markdown pipeline and revision history, taxonomy plus the media library, presentation (page routing, menus, settings, themes) and the admin UI.

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

# the browser side, from this repo - a stub DOM, no dependency, no build step
node assets/dynamic-list.test.js
node assets/admin.test.js
```

The PHP suite covers what the server sends; the two JS suites cover what the browser does
with it. Run both when touching the admin — a list whose constructor could not run was released
once because only the first existed.

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

**Getting in is rate limited.** `RateLimiter` counts attempts in a sliding window, in `auth_attempt`, and refuses once a key has had its allowance — logging in, asking for a reset, and submitting a reset token, each with a per account and a per address limit. Four things about it are load-bearing:

- **Both limits, always.** Per account alone is a way to lock somebody out by failing on their behalf; per address alone does nothing about a botnet with one target.
- **A sliding window, not a lockout.** A fixed lockout is a state somebody else can keep an account in indefinitely, one failure at a time.
- **A success clears the account key, never the address key.** Otherwise one valid account wipes the address count between guesses at everybody else's.
- **Unknown addresses are counted too, and every key is stored as a digest.** Skipping them would make the limit a way of asking who has an account here; storing them plainly would keep a list of what strangers typed into a login form.

It rests on `Request::ip()` being trustworthy, which it only is from micro 0.18.0 and only when **`request.trusted_proxies`** names the proxy in front of the site. Behind an unconfigured proxy every visitor is one address with one allowance.

**The admin role holds every permission implicitly** (`DpressUser::hasPermission()` short-circuits on it), so it is seeded with none and a permission invented later by a plugin needs no retroactive grant. It is also seeded `removable = false`, and `user:role -revoke` refuses to take it from the last administrator.

**The site always keeps a way in.** `UserService::guardLastActiveAdmin()` refuses to block, demote or delete the last administrator who can still sign in, and it is in the *service* because the rule is about the state the site may end up in — it used to live in `UserCommands`, where it guarded one CLI flag while the admin UI walked past it three different ways. It counts **active** administrators: `AuthService::login()` refuses anybody who is not active, so an account that cannot sign in is not a way in, whatever roles it holds.

**Permissions are plain strings** — `Permissions::add()` is all a plugin needs; there is no lookup table to migrate.

Access tokens carry the user's roles and permissions in the payload, so an authorized request costs no database query — but a role change only lands on the next refresh, which is why the access TTL is 15 minutes. `AuthService::refresh()` revokes the old refresh token and issues a new one, so a stolen token is usable at most once.

Login failures are deliberately indistinguishable: a wrong password, a blocked account and a pending account all produce the same message, and `createPasswordResetToken()` returns `null` for an unknown address rather than throwing. Neither should become a way of finding out who has an account.

### Entities

**Every entity declares its table name** — `#[Table(name: 'user_role')]`. Nothing derives it from the class name, so there is no CamelCase-to-snake_case guess to disagree with later. Add the attribute when you add an entity.

**Before 1.0 there are no rename migrations.** Change an entity, then rebuild the development database with `database/reset.sh` in the app, which drops it, installs, seeds and regenerates `database/example-data.sql`. The seed script goes through the services, so the example data has a real audit trail.

### Content

One `Content` table with a `type` column (`post` | `page`) — the reasoning is in the plan's §4.1. Per-type permissions still work: `Permissions::forContent($type, 'create')` gives `post.create` or `page.create`, resolved from the row.

**The lead/body split rule**: the first line consisting *solely* of `---` **that is not line 0**. At offset 0 it is opening YAML front matter, and a document starting with a separator would get an empty lead. `----`, `- - -` and `--` are not separators. A document with no separator is all lead and no body — a short note is exactly that.

**`lead_html` / `body_html` are a cache of `markdown`.** Only `ContentService::renderInto()` writes them; `dpress content:rerender` rebuilds everything after a rendering change. Nothing else should assign those columns.

**Attachments are the author's, and nothing recalculates them.** The editor has an attachments panel under the textarea: attach, detach, and hide or show each one. `hidden` means "attached but left off the list at the bottom of the published page", which is what an image inside the article wants. **Nothing infers any of this from the text** — dpress 0.15.0 tried reconciling attachments against the markdown on save and it was withdrawn in 0.16.0, because it fights the author: detaching a row would re-attach on the next save, and a hidden flag set by hand would be overwritten. Removing a file from the text is a separate act from detaching it, and both are the author's.

The panel writes immediately over `Dpress.send()` rather than on Save, so the form is never reloaded and there is one write model — the same as every other row action. That is why it needs a saved post: a new one has no id to attach to, and the buttons say so rather than pretending.

**`status` is not an editable field.** `ContentService::update()` ignores it deliberately — becoming visible sets `published_at` and is what a feed, a cache or a plugin listens for, so it belongs to `publish()` / `unpublish()`. Anything that offers a status control routes through `ContentAdminController::applyStatus()`, which checks `Permissions::forContent($type, 'publish')` and calls those two. Do **not** put `status` back into `contentData()`: `create()` honours what it is given while `update()` drops it, so the same field published a new post and silently did nothing to an existing one, and neither path asked whether the person may publish. The stock `editor` role holds `post.publish` but not `page.publish`, so the gap was reachable with the default roles.

Every change emits **both** `content:updated` and the type alias `post:updated`, so a plugin that only cares about posts does not have to inspect the type on every content event of the site.

**Deleting a page re-parents its children** rather than cascading — a cascade would take a whole subtree out because somebody removed one page in the middle, and it would happen inside the database where no event fires and nothing is audited. Same reasoning as the audited relation tables.

`ContentHistoryService` is the only place that queries the `_aud` mirror; `AuditService` writes it and never reads it.

### Taxonomy and media

`Category` is hierarchical, `Tag` is flat — that structural difference is why they are separate entities rather than one with a `type` column, unlike the post/page split.

**The media library is central.** Content *references* media; `ContentAttachment` is a link, never a copy. Deleting an attachment removes the link, not the file.

**Write once.** A stored path is never reused: `MediaService::replace()` creates a *new* item and marks the old one deleted, because overwriting would rewrite every historical revision that shows it, silently, with nothing in the audit.

**Deleting marks `deleted_at`.** `purge()` is the only thing that removes bytes, and the CLI refuses it without `-confirm` — it is the operation that breaks history.

**Mime types are sniffed from the bytes**, never taken from the client or the extension, and checked against an allowlist.

**Derivatives are lazy and are a cache.** `…-thumb.jpg` is served by Apache when it exists; when it does not, the `!-f` rewrite lands on `MediaController`, which generates it. `media:regenerate` just deletes them. Never write those files from anywhere else.

**SVGs are sanitised on the way in.** An SVG is a document, not a picture: it can carry `<script>`, event handlers, `<foreignObject>` with arbitrary HTML, external references, and entities that expand until the parser dies. `MediaService::store()` runs the bytes through `SvgSanitizerInterface` **before** the file is moved, so what lands on disk is already clean and there is no window where the original is reachable.

The shipped implementation wraps `rhukster/dom-sanitizer` (MIT — `enshrined/svg-sanitize` is the better-known one and is GPL, so it cannot ship inside an MIT library; a site can bind it itself). It keeps an allowlist and then dpress strips **every absolute reference** on top, because the library only catches an external URL inside a CSS `url()` — a plain `<image href="http://elsewhere/pixel.png">` survived it, and at the file's own URL that is a tracking pixel firing from this origin. A stored drawing should be self-contained.

The uploads `.htaccess` still sends a strict CSP for `.svg`. That is the second lock, for anything that predates the sanitiser or gets past it — not the mitigation.

**`media:sanitize` is the only thing that rewrites a stored file**, for a library that predates the sanitiser. Write-once exists so a historical revision keeps showing the image it showed; here the point is that what a file used to contain must stop being served. It reports by default and needs `-confirm`.

**A joined query must qualify overlapping field names.** `tag_cloud` joins `content`, which also has `id` and `slug`; MariaDB rejects the unqualified select as ambiguous. `CoreQueries::table()` gives the unescaped name for that.

### Presentation

**Pages live at their own paths** via the catch-all route, which the router matches only after every exact and segment route — so adding a controller later cannot end up behind it. Slugs are globally unique, so the last segment finds the page; the ancestors are checked anyway, and a non-canonical path **301s** to the real one. A 302 there would leave both URLs live as far as a search engine is concerned.

**The admin wears dpress's own logo** (`assets/logo.svg`, served by `AssetController`) whoever the site belongs to. `site_logo` is the site's mark for the site's own pages and the admin does not read it; `site_icon` is the tab icon on both, because that is the tab an editor keeps open next to the site.

**The logo and the icon are settings that name a file** (`site_logo`, `site_icon`), not media items. The library is content and a header logo is chrome: it renders before anything has been uploaded, on pages with no content on them, and deleting a picture must not be able to take the header down. They store a path - `AbstractController::siteAsset()` resolves it against `app.base_url`, so the value survives the site moving out of a subfolder - and anything carrying a scheme is left alone. With neither set, both headers render the site's name as before.

**Settings vs config.** `SettingService` reads the database first and falls back to `dpress.ini`. Anything needed *before* the database is reachable — the connection, the JWT secret — stays in the config. Everything an editor may change while the site runs is a setting, and settings are audited.

**A theme is a folder under `themes/` with a `theme.ini`.** Dropping one in installs it; there is no registry. The active theme is a setting, so switching is a runtime action. A setting naming a missing theme falls back to the built-in templates rather than fataling.

**Menu items store a target, not a URL** — `content` / `category` / `tag` / `url` / `home` plus an id — so renaming a page moves its entry with it. `MenuService::tree()` resolves at render time and drops an item whose target is gone. One menu per place: assigning one moves any other out.

**Menus are not audited, settings are** (plan §4.4). A menu editor rewrites the tree wholesale, so its history would be churn.

### The admin

Everything behind `/admin`. **A screen is two actions**: one that renders the page — the filter form, the buttons, the editor, all of which the server decides — and, where there is a list, one that answers with JSON. The rows are what the browser asks for again on every sort, filter and page change.

**Moving between screens does not reload the page.** A link from one admin screen to another fetches the same URL with `?ajax=1`; `admin()` then renders `LAYOUT_PARTIAL` — `views/admin/main.phtml`, the `<main>` element and nothing else — and the browser swaps it in with `outerHTML`. That is `outerHTML` on purpose: it parses in the live document, where an inserted `<script>` stays inert but an `onclick` attribute still works, which is not true of a `DOMParser` document or a `<template>`. **The full layout fetches the same file**, so a partial can never contain something a whole page would not have. What the chrome cannot work out for itself rides on the element as `data-title` and `data-section` — attributes rather than headers, because a title with an accent in it does not survive a header.

**Anything unexpected is a real navigation.** Not ok, redirected somewhere else, or a body that does not start with `<main>`: the browser is handed the URL. Which links get caught at all comes from `data-admin-url` and `data-route-param` on the body, because with `router.use_rewrite` off every screen shares one path and the route is a parameter — and being wrong either way costs a partial load and nothing more.

**Anything a screen leaves outside `<main>` is stale after the first navigation.** That is why the hidden CSRF form is inside it: the token is regenerated and stored on every render, so a form left in the layout would be carrying one the server has already replaced.

`#[Authorize]` sits on **`AbstractAdminController`** and nothing else needs it. That only works because `AttributeProcessor` looks for a class-level attribute along the inheritance chain (micro 0.17.0): PHP attributes are not inherited, and before that fix the base's `#[Authorize]` applied to nothing and every admin screen was reachable anonymously. Each action still checks the permission it actually needs — "may open the admin" and "may delete a user" are different questions.

**The lists render themselves.** `assets/dynamic-list.js` is a rewrite of `dynart-micro-js/dynamic-list.js` with no jQuery and no build step. A list screen is a filter form, a container and one JSON object — no per-screen JavaScript — because `Dpress.list()` takes column views by *name* and row actions as `link` or `post`. Nothing there can be a function; it has been through JSON. **The object is a `data-list` attribute, never an inline `<script>`**, because a screen that arrived as a partial was inserted and inserted HTML does not run its scripts; `Dpress.init(root)` binds whatever it has not bound yet.

**A list screen is one request, not two.** The page seeds `firstPage` into that configuration — the same rows the endpoint would answer — so the table arrives filled and the endpoint is only asked again when a sort, a filter or a page changes. `firstPageContext()` takes the sort from the configuration the browser is about to be primed with, and anything actually in the URL still wins; a seed ordered differently from what the list thinks it is showing would rearrange itself on the first click.

**A column view escapes by default.** `DynamicListColumnView.text` escapes, `html` does not, and the opt out is spelled out at the call site. A post title is whatever somebody typed.

**The filter form is the list's state.** Sort, direction, offset and page size live in it as hidden inputs next to whatever the server rendered, so one serialize produces the whole request and a plugin adding a filter field needs to tell the list nothing.

**Never hand a request straight to `addOrderBy()`.** The name goes into the SQL. `ListRequest` drops anything not in the whitelist the screen passes in, and `CoreQueries::applyListOptions()` checks the shape again because a second caller may build the context by hand. The page size is clamped rather than rejected — a browser asking for everything gets a page.

**An action that answers with data must return `AbstractAdminController::answer()`.** `Form::process()` mints a fresh CSRF token on every run and stores it in the session, so validating one action spends the one printed on the page. Page-reloading actions never notice; two AJAX actions in a row do, and the second is refused as a forgery. `answer()` hands the new token back and `Dpress.keepToken()` puts it in the hidden form — from every answer, including a rejected one, which spent the token getting as far as being rejected.

**Deletes and publishes are POSTs.** A link that changes something can be followed by a prefetcher, a crawler or an `<img>` on another page. Every admin screen renders one hidden form carrying a CSRF token, inside `<main>` so a partial load brings a fresh one; `Dpress.post()` points it at the action and submits it. `requireAction()` is what validates it, and a failure is a 403 rather than a redirect with a message.

**The markdown field is a textarea with a toolbar, deliberately not an editor.** A markdown field whose value is anything other than what the author typed eventually rewrites somebody's document on save, and the content model is "the markdown is the truth".

**The sections are a sidebar on the left**, one icon each, and the icon is an inline SVG so it takes the colour of the link it sits in. `--radius` (3px) and `--width` (1280px) in `admin.css` are the two numbers the whole admin is built from; a component with its own corner radius has drifted.

**An icon is `AbstractAdminController::icon('name')`**, which renders `views/admin/icon-<name>.svg.phtml` — the same convention as the media category icons — and falls back to `icon-section.svg`, so a section or a row action a plugin adds is never invisible for want of a drawing. **Its result is markup**, and that is what an `icon` key means both in the navigation and in a row action, where the list assigns it as `innerHTML`; a row action with no icon falls back to its escaped title. Nothing may build one out of a request.

**A row action is an icon with its name in `title` and `aria-label`.** The label is not decoration: an icon has no accessible name of its own and a `title` is a tooltip, which a screen reader may or may not announce.

**The uploads `.htaccess` is written by `MediaStorage::protect()`**, once at install, and `dpress media:protect` rewrites it. **Every module-specific directive in it must sit inside an `<IfModule>`**: Apache does not skip a directive it does not recognise, it refuses the whole directory with a 500, so an unguarded `php_flag` turned every image on a PHP-FPM site into a server error. The `<FilesMatch>` deny is the actual lock and stays outside — it needs no module and is what stops an uploaded `.php` being a remote shell.

**The assets are served from the package** by `AssetController`, so installing the package installs the admin — no publish step to forget after an update, which would otherwise leave last version's list code talking to this version's endpoints. The URL carries `Dpress::VERSION`, so the answer is cached forever.

**A template must never pass `get_defined_vars()` to a nested `fetch()`.** A template body is `include`d inside `View::fetch()` and shares its scope, so that hands down the *path of the file being included*; the nested fetch extracts it over its own and includes the caller instead — forever. micro 0.17.0 unsets the reserved names, but naming the variables is still the honest way to write it.


### Logging

**`DpressLogger` exists so a dpress site never logs into its own document root.** `Logger`'s default directory is the relative `logs`, and a relative path resolves against the working directory — `public/` for a web request — so a site that configured nothing served its own stack traces at `/logs/...`. Both apps register it in their constructor, before `fullInit()` builds the logger.

The config keys are **`log.dir` and `log.level`**, not `logger.*`. Getting them wrong is silent: the level falls back to `error` and the directory to the dangerous default.

`log.level = debug` makes `Database` log every query with its parameters, which is how you count queries per request — see [docs/performance.md](docs/performance.md) for the method and the baseline — and it writes a file per query, so it is a development setting only.

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
- **Admin lists** get their sortable columns from a `SORTABLE` constant on the controller, and their rows from a `row()` method that names the fields — an entity handed over wholesale sends `markdown` and `body_html` to the browser on every list request.
