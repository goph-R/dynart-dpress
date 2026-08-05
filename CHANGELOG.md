# Changelog

All notable changes to **dpress** are documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/).

---

## [0.14.2] &ndash; 2026-08-05

### Fixed
- **The uploads `.htaccess` 500'd every file under PHP-FPM.** `php_flag engine off` is a mod_php directive, and Apache does not skip a directive it does not recognise — it refuses the whole directory with `Invalid command`. On any site not running mod_php, every image, every download and every thumbnail was a server error. `Header` had the same problem waiting behind it, since mod_headers is not enabled everywhere either. Every module-specific directive is now inside an `<IfModule>`.

### Added
- **`dpress media:protect`** rewrites `uploads/.htaccess`. It is written once at install and left alone afterwards, so an installation that got the old one had no way back out — and could not be fixed by re-running `install` either.

### Notes
Nothing is lost when `php_flag` is skipped. The `<FilesMatch>` rule below it is the actual lock and does not depend on any module: those files are not served at all, so there is nothing left to interpret. Verified against the running Apache — an uploaded `.php` answers 403 and its contents never execute, while images and SVGs serve normally with the CSP header intact.

---

## [0.14.1] &ndash; 2026-08-05

### Fixed
- **The editor's Status select did nothing.** Setting a post to Published and saving said "Saved." and left it a draft. `ContentService::update()` ignores `status` on purpose — becoming visible sets `published_at` and is what a feed, a cache or a plugin listens for, so it belongs to `publish()` / `unpublish()` — but the editor handed `status` to `update()` anyway and it was dropped on the floor. The editor now makes the transition through the same two service methods the row actions use, so it is announced exactly once however it was asked for.
- **Creating content ignored the publish permission.** `ContentService::create()` *does* honour the status it is given, and nothing checked whether the person may publish before passing it on — so the same select that did nothing on edit published on create. It is checked now, on both paths.
- **The Status select is only rendered for somebody who may publish** that kind of content. The stock `editor` role holds `post.publish` but not `page.publish`, so this was not hypothetical: that role got a select on the page editor that the server then ignored.

### Notes
The three are one bug seen from three sides: `status` was travelling with the ordinary fields, through a method that honours it and a method that ignores it, with nobody asking a permission question on the way. It travels on its own now, through `applyStatus()`, which asks both questions in one place — and a status that is neither `draft` nor `published` is not a third state to move to.

Saving a published post without touching the select no longer re-publishes it. It never visibly did, but it would have moved `published_at` and announced the post again on every corrected typo.

---

## [0.14.0] &ndash; 2026-08-05

Guessing a password now costs something.

### Added
- **Rate limiting on the way in.** `RateLimiter` counts attempts in a sliding window and refuses once a key has had its allowance. Three scopes: logging in (5 per account, 20 per address, in 15 minutes), asking for a password reset (3 per account, 10 per address, in an hour) and submitting a reset token (10 per token, 30 per address, in an hour). Every number is overridable — `dpress.rate_limit.<scope>.account`, `.address`, `.window` — and `dpress.rate_limit.enabled = false` turns the whole thing off.
- **`auth_attempt` table** and migration `0007`. A row per attempt rather than a counter per key, because a counter cannot answer "how many in the last fifteen minutes" without also storing when it was last reset. Not audited, and pruned past the longest window: it is a working set that expires, not a record of anything.

### Notes
**Two limits, always.** A per account limit stops one account being hammered; on its own it hands anybody a way to lock a person out by failing on their behalf. A per address limit stops one password being sprayed across every account; on its own it does nothing about a botnet with a thousand addresses and one target. Neither is optional and neither is sufficient.

**A sliding window, not a lockout.** Once the oldest attempt in the window expires there is room for another. A fixed lockout with a timer is a state somebody else can keep an account in indefinitely, one failure at a time.

**Attempts are counted for addresses that have no account here.** Not counting them would make the limit itself a way of asking who has an account, and guessing addresses is how a spray attack starts. For the same reason the key is stored as a sha256 digest: the set of addresses typed into a login form is exactly the set this site has no business writing down.

**A success clears the account, never the address.** Otherwise anybody holding one valid account could wipe their own address count between guesses at everybody else's.

**The reset form still answers "check your inbox" when it is over the limit.** An error there would tell anybody willing to try that somebody has been asking about that address, and the endpoint exists precisely so that it says nothing about who has an account. Nothing is sent, and the mailbox stops being something a stranger can fill.

**This needs micro 0.18.0**, where `Request::ip()` stopped believing `X-Forwarded-For` from anybody who is not a configured proxy. A limit keyed on an address the client can choose is decoration. **If the site is behind a proxy, set `request.trusted_proxies`** or every visitor shares one address and one allowance.

Registration is not limited. It is one call to the same limiter if a site wants it, but `registration_open` is false by default and a flood of pending accounts is a nuisance rather than a way in.

---

## [0.13.0] &ndash; 2026-08-05

The admin moves between screens without reloading itself, and a list screen costs one request instead of two.

### Added
- **Partial navigation.** A link from one admin screen to another fetches the same URL with `?ajax=1`, which answers with that screen's `<main>` element and nothing else, and the browser puts it where the old one was. The header, the navigation, the stylesheet and the script are the same on every screen; fetching all of them again was throwing away what the browser already had. Back and forward work, the tab's title follows, and the current navigation item is re-marked from a `data-section` the fragment carries.
- **`AbstractAdminController::LAYOUT_PARTIAL`**, a real template - `views/admin/main.phtml` - that the *full* layout also fetches. There is one definition of what `<main>` is, so a partial can never contain something a whole page would not have.
- **`firstPage` on a list configuration.** The screen renders the first page of rows into the list rather than making the browser come back for them, so a list screen is one request on a full load and on a partial one alike, and the table arrives filled instead of flashing empty. `AbstractAdminController::firstPageContext()` builds it, taking the sort from the same configuration the browser is about to be primed with - anything actually in the URL, a filter or a sort somebody linked to, still wins.

### Changed
- **The hidden CSRF action form moved inside `<main>`.** Its token is generated on every render and stored in the session, so a form left outside the swapped part would keep the token of a screen that has since been replaced and every row action after the first partial load would be refused as a forgery.
- **A list is configured through `data-list` rather than an inline `<script>`.** Inserted HTML never runs its scripts, and this is how every other piece of `admin.js` already finds its work: `Dpress.init()` binds whatever it has not bound yet, on the first page and after every navigation.
- The layout's unused `script` block is gone rather than left as a trap that silently swallows its contents on a partial load.
- Every admin template takes its layout from `$admin_layout` instead of naming one. A test fails if a new one names its own, because that would answer a partial request with an entire document.

### Notes
**Anything unexpected is a real navigation.** An expired session, a deleted row, a screen that is not a fragment: the browser is handed the URL and renders it properly. The same goes for deciding which links to catch at all - with rewriting off every screen shares one path, so the server says how routes are written, and being wrong either way costs a partial load and nothing more.

**The fragment is HTML, not JSON and not headers.** What the chrome cannot work out for itself rides on the element as `data-title` and `data-section`. A title with an accent in it survives that; a header would mangle it. And `?ajax=1` in a browser shows exactly what the browser will be given.

**The seed is a head start, not a second source of truth.** The first sort, filter or page change goes to the endpoint like any other. Both sides read the same `ListRequest`, and every seeded page on the dev site is byte-identical to what its endpoint answers.

---

## [0.12.1] &ndash; 2026-08-04

### Changed
- **The admin wears dpress's own logo, always.** It ships in `assets/logo.svg` and `AssetController` serves it with the version in the URL, so it is cached forever like the rest of the admin's assets - and it is safe for exactly the reason an uploaded SVG is not: it is a file this package ships, not one somebody sent us. `site_logo` stays what it was, the site's own mark for the site's own pages, and the admin no longer reads it.
- The site's name sits next to that logo. "Which site am I in" is a question anybody running two of them has, and the header used to answer it.
- The tab icon in the admin is still `site_icon`, because that is the tab an editor keeps open next to the site itself.

---

## [0.12.0] &ndash; 2026-08-04

A site can have a logo and an icon.

### Added
- **`site_logo` and `site_icon` settings.** The logo replaces the site's name in both headers - the admin's and the front end's - and the name becomes its `alt`, because a site with both would say the same thing twice on every screen. The icon becomes `rel="icon"` and `apple-touch-icon`. Neither is set by default, and with neither set nothing changes: the name renders as it did.
- Both are edited on the Settings screen and are audited like every other setting, so "who changed the logo" is answerable.

### Notes
**They store a path, not a URL.** `/static/logo.svg` is resolved against `app.base_url` at render time, so the value survives the site moving out of a subfolder onto a domain of its own - the move that would otherwise silently break every stored absolute URL. Anything that already carries a scheme is left alone, so a logo on a CDN or one inlined as a `data:` URI still works.

**They are settings, not media items.** The library is content; a header logo is chrome. It has to render before anything has been uploaded, on pages that show no content at all, and deleting a picture from the library must not be able to take the header down with it.

---

## [0.11.3] &ndash; 2026-08-04

More admin polish: the row actions are icons.

### Changed
- **Edit, Publish, Move back to draft, History, Delete, Rename and Restore are icons now**, with the name in `title` *and* `aria-label`. Four words per row is a paragraph nobody reads, and the actions column grew with every action a plugin added. Publish is an eye and unpublish is the same eye crossed out, so the pair reads as one state and its opposite; rename gets the text cursor rather than a second pencil, because a menu row carries both and two pencils would say they are the same kind of thing.
- **"Items" on a menu is called "Edit"** and gets the same pencil as everywhere else. A menu's items are what there is to edit about it, and the first action of every other list is Edit.
- `AbstractAdminController::icon()` renders `views/admin/icon-<name>.svg.phtml` and is what both the navigation and the row actions go through, so **`icon` means markup everywhere it appears** — including the picker's `Choose`, which no longer sets it and simply falls back to its title. Missing icons fall back to a generic mark, so a row action a plugin adds is never invisible for want of a drawing.
- The menu items screen is a plain table rather than a dynamic list, and now uses the same two icon files, so it does not end up the one screen with words on it.

### Fixed
- **A row action rendered as an icon had no accessible name.** `title` is not one that can be relied on: it is a tooltip, and whether a screen reader announces it is a setting. The list sets `aria-label` from the title whenever an action has an icon, and the JS test asserts it — along with the escaping on the fallback, since `icon` is `innerHTML` and a title is not.

---

## [0.11.2] &ndash; 2026-08-04

Admin polish.

### Changed
- **The sections moved to a sidebar on the left, with an icon each.** A horizontal bar has to stay short or it scrolls, which is what puts everything a plugin adds out of sight; a vertical list grows downwards for free. The icons are inline SVG rather than `<img>`, so each one takes the colour of the link it sits in — including the inverted one marking the current section — and a font of glyphs that has to load before the nav is readable is not worth it for nine shapes.
- A section names its icon in `navigation()` (`views/admin/icon-<name>.svg.phtml`), separately from the key that marks it current. A section a plugin adds can point at an icon that already exists, and anything the admin cannot find falls back to a generic mark rather than a gap.
- Below 1000px the labels go and the sidebar is an icon rail; below 720px it lies down above the content. **No hamburger**: every section stays one click away, which is the whole point of a menu with nine items in it.
- **Corners are 3px everywhere**, from `--radius`. One small value rather than one per component, so a panel, a button, an input and a badge read as the same material. The status badges were pills and are not any more.
- **The admin is 1280px wide at most**, from `--width`. The dark bar still reaches both window edges, because a band that stops short of them looks like a card that failed to load; everything inside it and below it stops at 1280.

### Fixed
- `composer.json` requires `ext-dom`, `ext-libxml` and `ext-gd` explicitly. All three were already needed — the sanitiser cannot parse without the first two and derivatives cannot be generated without the third — and a missing one should be a message from Composer rather than a fatal on the first upload.

---

## [0.11.1] &ndash; 2026-08-04

### Fixed
- **Saving a post or a page from the browser was a fatal error.** `Content::$parent_id` and `$featured_media_id` are typed `?int`, and both are filled from a control whose "nothing chosen" value is the empty string — assigning that is a `TypeError`. `ContentService` coerces a nullable foreign key now, because it is the one place that knows the column is an id and a service that fatals on ordinary form input is a trap for the next caller too.
- `ContentAdminController` no longer hands raw form values to the service. It names the columns it passes on, so the CSRF token, the tag string, the category boxes and whatever a plugin adds cannot reach the entity by accident — and a field the form does not have stays absent, because `update()` reads absent as "leave it alone".

**Every automated test passed while this was broken**, which is the part worth remembering: the curl checks sent the fields they cared about and left the empty ones out entirely, so `?? null` covered for them. A browser sends every field in the form, empty ones included. The new tests use the values a form actually posts.

---

## [0.11.0] &ndash; 2026-08-04

Phase 6 begins: SVG uploads are sanitised.

### Security
- **An uploaded SVG is sanitised before it is stored.** An SVG is a document, not a picture: it can carry `<script>`, event handlers, `<foreignObject>` with arbitrary HTML in it, references to other sites, and XML entities that expand until the parser dies. `MediaService::store()` now runs the bytes through `SvgSanitizerInterface` **before** the file is moved into place, so what lands on disk is already clean and there is no window in which the original is reachable through the web server.
- **Every absolute reference is stripped, not just the ones in a CSS `url()`.** The library catches the latter; a plain `<image href="http://elsewhere/pixel.png">` went straight through it. Used via `<img src>` a browser will not fetch that — but the file is also reachable at its own address, and there it is a tracking pixel firing from this origin. A stored drawing should be self-contained, so the rule is the blunt one. `data:image/png` and friends stay, because an inert raster is worth embedding; `data:text/html` does not.
- A file that cannot be parsed as SVG once the executable parts are gone is **refused**, rather than stored empty. That is what happens to an entity attack: the doctype is stripped, the entities no longer resolve, and what is left is not a document.

### Added
- `SvgSanitizerInterface`, with `SvgSanitizer` over `rhukster/dom-sanitizer` bound to it. MIT, which is why it is that one — `enshrined/svg-sanitize` is better known and GPL-2.0-or-later, so it cannot ship inside an MIT library, though a site can bind it itself. **Rebinding the interface to something that returns its input is how you turn sanitising off**, and it should look exactly that deliberate; there is no config flag for it.
- `dpress media:sanitize [-id 1] [-confirm]` for a library that predates the sanitiser. **The only thing in the CMS that rewrites a stored file** — write-once exists so a historical revision keeps showing the image it showed, and here the point is precisely that what a file used to contain must stop being served. It reports by default and needs `-confirm` to write.
- `SvgSanitizerInterface::isClean()`, and `MediaService::sanitizeStored()` / `wouldSanitize()`.

### Changed
- The upload screen and `media:import` no longer warn that SVGs are unsanitised, because they no longer are. The uploads `.htaccess` still sends a strict CSP for `.svg`: that is the second lock, for anything predating the sanitiser, not the mitigation.

### Notes
`isClean()` asks whether an element or an attribute **would be removed**, counted rather than compared. Sanitising reserialises the document, so an untouched file comes back with different whitespace and attribute order — a byte comparison reported every SVG in the library as dirty, and a report that flags everything is one nobody reads. The dry run and `-confirm` go through the same question, so they cannot disagree about what needs rewriting.

---

## [0.10.1] &ndash; 2026-08-04

### Fixed
- **Every admin list threw `this.refresh is not a function`.** `DynamicList` called `refresh()` at the top of its constructor, but the methods are function *expressions* assigned to `this` further down — none of them exist until the constructor has run past them. The list now builds and fetches as the last thing it does.

### Added
- `assets/dynamic-list.test.js` — thirteen tests over a stub DOM, run with `node assets/dynamic-list.test.js`. No dependency, no build step. The PHP suite covers what the server sends and nothing covered what the browser does with it, which is how a constructor that could not run got released.

**The version is what busts the asset cache.** `AssetController` serves with `immutable, max-age=31536000`, so a browser that loaded the broken file keeps it until the URL changes — which is the whole reason this is a version bump rather than an edit in place.

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
