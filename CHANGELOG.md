# Changelog

All notable changes to **dpress** are documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/).

---

## [0.10.0] &ndash; 2026-08-04

Phase 5: the admin.

### Added
- **The admin UI.** Nine screens behind `/admin` — a dashboard, posts, pages, the media library, categories and tags, menus and their items, users, roles with a generated permission editor, and settings. Each is two actions: one that renders the page, and, where there is a list, one that answers with JSON.
- **`dynamic-list.js`** — the lists render themselves in the browser and ask the server again on every sort, filter and page change. Modelled on `dynart-micro-js/dynamic-list.js`, rewritten with no jQuery, no build step and no globals from a surrounding application. A **column view escapes by default**: a post title is whatever somebody typed, and returning it raw would put one editor's markup into every other editor's browser. `DynamicListColumnView.html` is the opt out, spelled out at the call site.
- **A list screen is a filter form, a container and one JSON object** — no per-screen JavaScript. `Dpress.list()` takes column views by *name* and row actions as `link` or `post`, because none of it survives being JSON. A screen that genuinely needs a callback still constructs `DynamicList` itself.
- `ListRequest` — turns `sort` / `order` / `offset` / `max` into a query context. **The sort column has to be in a whitelist the calling screen passes in**, because `Query::addOrderBy()` puts the name into the SQL. The page size is clamped rather than rejected, so a hand-written `max=100000` gets a page instead of the whole table.
- `AdminForms` — ten form builders plus `admin_action`, all through `FormFactory`, so a plugin can add a field to any admin screen and it renders with no template change.
- **`DpressForm` renders its own field types** — `markdown`, `media`, `checkboxes`, `permissions` — and falls through to the framework's partial for everything else.
- `AssetController` serves the admin's JS and CSS out of the package, so installing the package installs the admin. The URL carries the version, so the answer can be cached forever.
- `MediaView::rowUrl()` / `rowTag()`, `MenuService::itemRows()`, `TaxonomyService::countCategories()` / `countTags()`.

### Changed
- **Deletes and publishes are POSTs, not links.** A link that changes something can be followed by a prefetcher, a crawler or an `<img>` on another site. Every page renders one hidden form carrying a CSRF token, and a row action points it at the action and submits it.
- The core list queries honour `order_by` / `order_dir` / `offset` / `max` from their context. The name is checked against `^[a-z0-9_]+$` here as well as by `ListRequest` — this is the point where it stops being data and becomes SQL.

### Fixed
- **`ContentService::delete()` could not delete anything that had a tag or a category.** The relation tables carry a foreign key and deliberately no `ON DELETE CASCADE`, so the row was refused by the database. The links now go first, through `TaxonomyService` and `MediaService`, which is also what keeps "which categories did this post have when it was deleted" in the audit rather than losing it inside the database.
- **`MediaService::upload()` called `UploadedFile::tempName()`, which does not exist.** No HTTP upload had ever run — only `importFile()`, from the CLI and the seed.

---

## [0.9.0] &ndash; 2026-08-04

Phase 4: presentation — page routing, menus, settings and themes.

### Added
- **Pages at their own paths.** `PageController` takes the catch-all route, so `/about/contact` works. Because slugs are globally unique the last segment finds the page on its own, but the ancestors are still **checked**: a path that resolves to a real page by a route it does not live at gets a **301 to the canonical one**, so the same content cannot answer at unlimited URLs.
- **`Setting`** — audited, keyed by name, so its mirror is a per-setting timeline needing no replay. `SettingService` loads the table once per request and falls back to `dpress.ini`, so a fresh install works before anything is saved and an operator can still pin a value in the config.
- **`Menu` and `MenuItem`** — a menu is assigned to a *place* declared by the theme; items nest, and each stores **what it points at** rather than a URL, so renaming a page moves its menu entry with it. `MenuService::tree()` resolves targets at render time and **leaves out** an item whose target is gone: a menu entry that goes nowhere is worse than no entry.
- **`ThemeService`** — a theme is a folder under `themes/` with a `theme.ini`; dropping one in installs it. The active theme is a **setting**, not a config value, so switching it is a runtime action and is audited like any other setting. A setting naming a theme that is not installed falls back to the built-in templates rather than fataling on every page.
- **CLI** — `theme:list`, `theme:set`, `menu:list`, `setting:list`, `setting:set`.
- Permissions for `menu.*` and `theme.*`, a `page.phtml` with breadcrumbs and child listing, and a `menu.phtml` a theme can override.

### Changed
- `AbstractController` reads the site name and registration flag from `SettingService` rather than the config, so an editor can change them while the site runs.
- `RequestInterface` and `RouterInterface` moved into the shared registration — the CLI needs them too, because a menu item stores a target rather than a URL and listing a menu has to build one.

### Notes
- **One menu per place.** Assigning a menu to a place moves any other menu out of it, rather than silently rendering only the first.
- Menus are deliberately **not audited** (plan §4.4) — a menu editor rewrites the tree wholesale, so the history would record churn rather than meaning. Settings *are*.
- Requires dynart/micro 0.15.0 for catch-all routes and the redirect status code.

---

## [0.8.0] &ndash; 2026-08-04

Phase 3: taxonomy and the media library.

### Added
- **Taxonomy** — `Category` (hierarchical, with a thumbnail) and `Tag` (flat), plus the audited `ContentCategory` and `ContentTag` join tables. `TaxonomyService` with `setTags()` / `setCategories()` emitting one event per actual change, and `findOrCreateTag()` so an editor can type words rather than identifiers.
- **Media library** — `Media` as a central, audited library that content *references*; `ContentAttachment` is a link, never a copy, so one image can be a featured image on one post and an attachment on another without being stored twice.
- **`MediaTypes`** — an allowlist keyed by the mime type **sniffed from the file's own bytes**. A `.jpg` extension on an executable is refused, because a blocklist is a promise to have thought of every dangerous extension.
- **`MediaStorage`** — write-once paths: `2026/08/my-photo-a1b2c3.jpg`, the slug of the original name plus a **random** suffix. It also writes the `.htaccess` that stops the uploads folder executing anything and sends a strict CSP for `.svg`.
- **`ImageProcessor`** — GD behind its own class, with `thumb` / `medium` / `large` presets from config. Transparency is preserved, and an image smaller than the preset is copied rather than scaled up.
- **Lazy derivatives** — a template points at `…-thumb.jpg`; if the file is there Apache serves it and PHP never runs. If it is not, the existing `!-f` rewrite sends the request to `MediaController`, which generates it, writes it and serves it. Exactly one visitor per size pays.
- **`MediaView`** — `url()`, `tag()` and `icon()`. Non-images render as an inline SVG icon per category, stored as `icon-<category>.svg.phtml` so a theme can replace one like any other template.
- **Front end** — tags, categories, the featured image and attachments on a post; `/tag/<slug>` and `/category/<slug>` archives.
- **CLI** — `media:import`, `media:list`, `media:delete`, `media:purge`, `media:regenerate`, `taxonomy:list`.
- `Content.featured_media_id` gains its foreign key, and permissions for `category.*`, `tag.*` and `media.*`.

### Fixed
- `tag_cloud` selected `id` and `slug` unqualified while joining `content`, which has both — MariaDB rejected it as ambiguous. The fields are qualified now.

### Notes
- **SVG uploads are allowed and not yet sanitised**, deliberately (plan §11.5). The CLI prints a warning, and the uploads `.htaccess` sends `Content-Security-Policy: default-src 'none'` for `.svg` — an SVG used through `<img src>` is a non-scripted context regardless, so the remaining hole is somebody navigating straight to the file, which the header closes.
- **Deleting media marks `deleted_at`; the file stays.** `media:purge` is the only thing that removes bytes, and it refuses to run without `-confirm`, saying how many items reference it and that old revisions will break.
- Derivatives are a cache, so `media:regenerate` only deletes them — the next request rebuilds what it needs.
- The migrations were reordered so `Media` is created before `Content`: a `CREATE TABLE` can only reference a table that already exists.
- Requires dynart/micro-entities 0.6.0.

---

## [0.7.0] &ndash; 2026-08-04

### Changed
- **Every entity declares its own table name** with `#[Table(name: 'user_role')]`, so the tables are `dp_user_role` and `dp_role_permission` rather than `dp_userrole` and `dp_rolepermission`. Written by hand rather than derived from CamelCase: a guess eventually disagrees with what somebody wanted, and it does so silently.
- `CoreQueries` builds join conditions from `EntityManager::safeTableName()` instead of a `#ClassName` token

### Notes
- **No rename migration.** Before 1.0 the development database is rebuilt rather than migrated — see `database/README.md` in the app. Renaming the migration history table cannot be done by a migration anyway, since the runner reads that table to find out what has run.
- Requires dynart/micro-entities 0.5.0, where the `#ClassName` substitution learned about the name attribute.

---

## [0.6.0] &ndash; 2026-08-04

Phase 2: the content model, the markdown pipeline and the revision history.

### Added
- **`Content`** — one audited table with a `type` column for posts and pages, a globally unique slug, and a `(type, status, published_at)` index for the main listing
- **`MarkdownRenderer`** — CommonMark, plus the lead/body split. The rule is the **first line consisting solely of `---` that is not the first line of the document**: at offset 0 it would be opening YAML front matter, and a document that starts with a separator would get an empty lead. A document with no separator is all lead and no body.
- **`Slugger`** — folds accented characters to their ASCII base rather than dropping them, so a Hungarian title gives a readable slug instead of a row of hyphens, and appends `-2`, `-3` until the slug is free
- **`ContentService`** — create, update, publish, unpublish, delete, with full event coverage. Every change emits the generic `content:*` **and** the type alias (`post:created`, `page:created`), so a plugin can subscribe narrowly without inspecting the type.
- **`ContentHistoryService`** — reads the `_aud` mirror back: revisions with author and timestamp, a single revision, a field-level diff, `asOf()` for a point in time, and a recent-changes list
- **Queries** — `content_list`, `content_by_slug`, `content_children`, `content_archive`, all through `QueryFactory`
- **Permissions** — `post.*` and `page.*` per type, plus `content.history`; `Permissions::forContent()` resolves the pair from a row's type
- **CLI** — `content:create`, `content:list`, `content:publish`, `content:delete`, `content:history`, `content:rerender`
- **Front end** — the published posts on the home page and a single post at `/post/<slug>`. Somebody who may edit posts can preview a draft; a visitor gets a 404.
- `Cli\AbstractCommands` with a `param()` helper

### Fixed
- **Optional CLI parameters never reached their default.** `CliCommands::matchCurrent()` pre-fills every *declared* parameter with an empty string, so `$params['role'] ?? Role::NAME_ADMIN` always got `''` — `user:create` without `-role` failed with "There is no role named ''". `AbstractCommands::param()` treats an empty parameter as absent, which for a CLI is the same thing.

### Notes
- **`content_by_slug` defaults `published_only` to true.** The filter is on unless the caller asks for drafts, so a forgotten flag cannot leak unpublished work.
- Deleting a page **re-parents its children** rather than cascading. A cascade would delete a whole subtree because somebody removed one page in the middle, and it would happen inside the database where nothing is audited.
- The rendered HTML is a cache of the markdown, so it is only ever written through `ContentService::renderInto()`; `content:rerender` rebuilds it after a rendering change.
- `prefer-stable` added to the composer files — without it `minimum-stability: dev` pulled `league/commonmark` as `dev-main`.

---

## [0.5.0] &ndash; 2026-08-04

Phase 1 complete: the HTTP layer. Login, logout, registration, password recovery and the profile, all verified end to end against Apache and MariaDB.

### Added
- **`DpressWebApp`** — the middleware order that makes cookie-based login work: `JwtCookieReader` (40) lifts the access token out of its cookie, `TokenRefresher` (45) renews it from the refresh cookie when it has aged out, `JwtValidator` (50) decodes it.
- **`TokenRefresher`** — renews an expired access token before the validator sees the request. Without it a 15-minute access TTL means a 401 every 15 minutes for somebody who never logged out. It decodes nothing: the access cookie is set to expire slightly before its token, so an aged-out session arrives with no `Authorization` header at all, which is exactly the case it handles.
- **`AuthCookies`** — HttpOnly, SameSite=Lax cookies for both tokens; `jwt.cookie_secure` turns on `secure` in production
- **Controllers** — `AuthController` (login, logout, register, forgot-password, reset-password), `ProfileController` (`#[Authorize]`, so any logged-in user), `HomeController`, all on `AbstractController`
- **`CoreForms`** — the five identity form builders, and the `EmailValidator` / `MinLengthValidator` / `MatchFieldValidator` they use. `MatchFieldValidator` reads the other field off the form at validation time, which is what `AbstractValidator::setForm()` is for.
- `dpress user:status -email x -status active` — registration creates a *pending* user, so without this there was no way to activate one short of editing the database
- **Views** — a layout and the auth pages, every form rendered through `$form->fetch()` so a plugin-added field appears without touching a template
- `translations/micro/en.ini` — overrides the framework's built-in form messages with wording meant for a visitor rather than a developer

### Changed
- **`FormFactory::add()` and `QueryFactory::add()` register a `[Class, 'method']` builder in the DI container**, the same thing `Migrations::add()` does. The builder is resolved through the container, so without this every caller needed a second `Micro::add()` and found out at runtime.

### Notes
- Refresh tokens **rotate**: refreshing revokes the old one, so a stolen token is usable at most once. A spent token makes `TokenRefresher` clear the cookies and continue anonymously rather than throw — a stale cookie must never lock somebody out.
- `/logout` is POST only, so a link on another page cannot log a visitor out.
- **`Router::currentRoute()` reads the path from a request parameter**, not `REQUEST_URI`, so the rewrite has to pass it: `RewriteRule ^(.*)$ index.php?route=/$1 [QSA,L]`. `public/router.php` does the same for PHP's built-in server.
- Requires dynart/micro 0.13.0, which fixes the two `View` bugs that stopped a form and a layout being combined at all.

---

## [0.4.0] &ndash; 2026-08-04

### Added
- **Mail** — `AbstractMailer` renders, a subclass delivers. A mail is two templates: `<template>.phtml` for the HTML body and an optional `<template>.txt.phtml` for the plain text alternative, both fetched through `ViewInterface` so a **theme overrides either one independently**, exactly the way it overrides a page template. `send($name, $email, $subject, $template, $variables)`; `create()` renders without sending.
- `LogMailer` (the default) writes the mail to the log, so a password-reset flow can be walked through without an SMTP server and the reset URL is there to click. `NativeMailer` sends through PHP `mail()`, `multipart/alternative` when a text body exists.
- `Mail` value object with header-safe address formatting — a non-ASCII display name is base64 encoded, or it arrives as mojibake.
- `mail.mailer` config picks the mailer by short name (`log`, `native`) or by class name, so an application plugs in PHPMailer or Symfony Mailer with a subclass and one line.
- `mail:before_send` / `mail:sent` / `mail:failed` events; the before event carries the rendered mail and fires before the transport sees it, so a subscriber can still change it
- Default templates: `views/mail/layout.phtml`, `views/mail/password-reset.phtml` and its `.txt.phtml`
- `dpress mail:test -email x [-render]` — renders and sends a test mail, and reports which mailer is actually in use
- `Dpress::viewsPath()` / `translationsPath()` — the package ships its own views and translations, which live wherever Composer put them rather than under the site root, so they cannot use the `~` alias

### Notes
- **In `multipart/alternative` the text part comes first.** A mail client displays the *last* part it can render, so HTML has to be the later one.
- Requires dynart/micro 0.12.0 for `View::exists()` — deciding whether the optional text template is present by catching the exception from `fetch()` would also swallow a `MicroException` thrown from inside a template that does exist.

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
