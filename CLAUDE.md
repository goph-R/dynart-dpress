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

**A field *type* is a registration too.** `markdown`, `media`, `checkboxes` and `permissions` are added to micro's `FormWidgets` by `DpressServices::registerWidgets()`, one template each in `views/widget/`. That is the same call a plugin uses, deliberately: the CMS used to point `DpressForm::VIEW_INPUT` at one template holding all four, which worked exactly once — the framework's single override was spent, and nothing after the CMS could add a fifth type. Register new types; never reintroduce a template that branches on `type`.

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

**Every `---` after the first one is a page break.** A body with more than one is served a page at a time at `?page=N`, with *Previous* and *Next* under it - no new syntax, no new column, no new field on the editor, because the character that ends a lead is the character that ends a page. What is stored is `body_html` with a `<!--dpress-page-->` marker between the pages; `ContentPages::split()` cuts on it behind a `str_contains` guard, so a site that never breaks a post pays nothing. Each page is **rendered separately** rather than one render being cut up - cutting HTML in half is how a `<ul>` loses its closing tag - which costs the same thing the lead/body split already costs: a reference-style link defined on one page cannot be used on another. Page one is the post's own URL with nothing appended, and a page number that does not exist is a 404 rather than a clamp, so a crawler cannot index one post at every number there is. **A separator inside a fenced code block is not a separator**, which also fixed the lead/body split - it reads the same lines and had the same hole. See [docs/pages-in-content.md](docs/pages-in-content.md).

**`lead_html` / `body_html` are a cache of `markdown`.** Only `ContentService::renderInto()` writes them; `dpress content:rerender` rebuilds everything after a rendering change. Nothing else should assign those columns.

**A document says what it points at, not where that is.** `media#12`, `post#42`, `page#5`, `category#21`, `tag#7` in a link or image destination are resolved to full URLs at render time, so no stored markdown contains a hostname or a slug — see [docs/internal-links.md](docs/internal-links.md). Two things follow from resolution happening at *save* time. **Moving the site** means `app.base_url` plus `dpress content:rerender`, because nothing tells a site its own address changed. **Renaming** is handled: `update()` notices a moved slug or `parent_id` and re-renders whatever mentions that id, descendants included, since a page's slug is a segment of every path beneath it.

**Syntax highlighting is presentation, so none of it is stored.** ` ```php ` renders to `<pre data-enlighter-language="php"><code class="language-php">`, and EnlighterJS colours it in the browser. A server-side highlighter would write a `<span>` per token into `body_html` — markup about how a thing looks living inside the content, which is the mistake `media#12` exists to avoid one level down — and changing a theme or upgrading the highlighter would mean re-rendering every post. As built, both change every page at once and touch no document. **A page with no code block loads no script and no stylesheet**, which keeps the front end's zero-JavaScript property everywhere it can be kept; the test is a `str_contains`, like the shortcode guard. Off is the word `none`, not `''`, because `SettingService::get()` reads an empty value as absent and answers with the default. See [docs/code-highlighting.md](docs/code-highlighting.md).

**`> [!WARNING]` turns a blockquote into a panel**, in the three kinds info / warning / danger, with a plain quote as the grey fourth. GitHub's syntax, chosen because it is **valid CommonMark either way**: anywhere without dpress it is still a blockquote, still readable, with a visible marker where the styling would have been, and a convention that only works inside one CMS breaks the moment a document leaves it. It also means the content is markdown - bold, links and code work inside a panel because CommonMark parsed them before `Callouts` ran on `DocumentParsedEvent`, which a shortcode could never manage since it takes a string and a panel holds prose. What is stored is a class; the colour and the data-URI icon are in the stylesheet, on the same reasoning as the highlighting. An unrecognised marker is left exactly as written. See [docs/callouts.md](docs/callouts.md).

**Shortcodes run on the page, not at save, and that is the one place this rule is broken on purpose.** `{{ video('media#10') }}` is parsed by a CommonMark **inline parser** — so a shortcode inside a code span or a fence is left alone, which a regex over the markdown could never manage, and a CMS whose documentation cannot be written in it has a bad idea in it. What the parser writes into `body_html` is a **marker**, `<!--dpress-sc <base64>-->`, and `Shortcodes::expand()` swaps markers for output in `AbstractController::render()` — over the finished page, once, because content HTML reaches a template from five places and a theme may render any of them. The marker cannot be forged: `html_input => 'strip'` means raw HTML never survives a document, so only the parser writes one. Base64 because an argument containing `-->` would end the comment early. The cost is pay-per-use — 35.3 ms with none against 36.4 ms with one — and a page with none pays for a `str_contains`. See [docs/shortcodes.md](docs/shortcodes.md).

`MarkdownRenderer` knows nothing about media or posts and must keep it that way. It offers the CommonMark environment through `EVENT_ENVIRONMENT` and `InternalLinks` subscribes as a **Micro callable**, so the container builds none of it until something actually renders — which on a page view is never. **`LinkTargets` keeps no answers**: it memoised them once and a rename re-rendering its referrers in the same request got the URLs from before the rename, so the rename appeared to do nothing. The dedup that is safe is per document and lives in `InternalLinks`.

**An attachment is a file somebody attached; an image in the body is a reference in the text.** Two different things, kept in two different places. The toolbar's image button writes `![alt](media#12)` and attaches **nothing**; "Add attachment" attaches and writes nothing. A file can be both, either or neither, and **nothing infers any of it from the text** — 0.15.0 reconciled attachments against the markdown on save and it was withdrawn in 0.16.0 because it fights the author, and 0.24.0 removed `ContentAttachment.hidden`, which only existed because inserting a picture used to attach it. Removing a file from the text is a separate act from detaching it, and both are the author's.

What the split costs is that `usageCount()` can no longer see an inline image in a table, so it also counts `media#<id>` in `markdown` — a `like` candidate count, on the same terms as `referrerIds()`, advisory before a purge and never blocking.

The panel writes immediately over `Dpress.send()` rather than on Save, so the form is never reloaded and there is one write model — the same as every other row action.

**"New" writes a row, so there is no such thing as an unsaved post.** An immediate write needs an id, and asking somebody to save an empty post and come back before they may attach a file to it is a strange thing to ask. So `/admin/content/<type>/new` is a **POST** that calls `ContentService::startDraft()` and redirects to the editor for the row it made. `create()` is gone; `edit()` is the only editor, which is the real prize — no screen has to answer "and what does this do before the post exists?" ever again.

An **auto-draft** is that row: `Content::STATUS_AUTO_DRAFT`, deliberately **not in `STATUSES`**, which is what the status select offers and what `assertStatus()` accepts — so it cannot arrive from a form or from `content:create`. It is set in exactly one place and left in exactly one other, the first `update()`, which promotes it to a draft and resolves the slug from the title. Two things make it safe rather than merely convenient: **`applyContentFilters()` excludes it from every listing**, because it is not content until somebody saves it; and **`AdminForms::content()` fills the form as if the post did not exist**, above all the placeholder slug, which offered back would be posted as though it had been meant and become the URL.

`startDraft()` **reuses the author's existing auto-draft** of that type, so clicking New five times is one row, a file attached before wandering off is still there on the way back, and the table is bounded at one row per author per type — which is why `content:prune` is a tidy-up rather than a cron. The honest cost is two tabs: open New twice, save the first, and the second is editing the post the first one made. Nothing is lost, and it is the same surprise as two tabs on one post, which this CMS has never guarded against either.

**A preview writes nothing, and skips the CSRF token for a reason.** `ContentAdminController::preview()` is posted from the editor by a submit button carrying `formaction` + `formtarget`, stores the boxes in the session under a one-off token and redirects, so every page after that is a GET of a real address - which is what lets a body written in `---` parts page through on links, with the theme rendering the pager it always renders. The GET builds a `Content` that lives for one request and never reaches the entity manager, and renders the front-end template through `render()` so it comes out in the theme. **The session, not the database**: the post is still never written. Saving first and looking after would put unsaved edits **live** on a published post. The form is made with `csrf: false` because `Form::process()` mints a fresh token into the session every time it runs — checking it here would spend the one printed on the editor page still open behind the new tab, and the next Save would be refused as a forgery. That is safe only because the route writes nothing and the renderer strips HTML; do not copy the pattern to anything that does. The **View** button is a separate thing and shows for a draft too: the front end has always served an unpublished post to anybody holding `post.update`, so only the button was hiding it.

**Where a post lives is the `post_path` setting**, `/post/<slug>` or `/<slug>`, and nothing in the schema had to change for it: `Content::$slug` is unique across posts and pages, so a root-level URL has exactly one answer. `publicPath()` decides, `postPath()` answers for a listing row, and every template is handed `$post_path` as the prefix - never hardcode `/post/` in a view. `/post/<slug>` 301s to whichever shape is in force, so the setting is safe to change either way. **A link inside a post is resolved at save time**, so switching needs `dpress content:rerender` or the links between posts stay on the old shape - working, as redirects, which is the sort of thing nobody notices.

**Tables are in the converter, not in a subscriber.** `TableExtension` sits beside `CommonMarkCoreExtension` in `MarkdownRenderer::converter()` because a table is syntax rather than a policy - nothing to configure, nothing to ask a service, no setting. The subscribers are the features that had a decision to make. Column alignment arrives as an `align` attribute, so any stylesheet that sets `text-align` on cells must answer `[align]` too or it silently discards what the author wrote.

**A bare URL in prose is linked, and code is not prose.** `Autolinks` subscribes to `MarkdownRenderer::EVENT_ENVIRONMENT` like `InternalLinks` and the callouts do, so the renderer still knows nothing about settings. It adds `HttpAutolinkParser`, which is CommonMark's own `UrlAutolinkParser` narrowed by `getMatchDefinition()` to `http://` and `https://` - the library's version also links a bare `www.` host and turns an email into a `mailto:`, and neither was asked for. Code is safe because an inline parser does not run inside a fence or a code span, which is a property of where it is plugged in rather than a check, so it has a test. The `autolink` setting is **on by default** and, like `post_path`, takes effect at render time - changing it wants `dpress content:rerender`.

**Who wrote it is its own permission.** `post.assign_author` / `page.assign_author`, and not part of `update`: writing a post and deciding who wrote it are not the same authority, so the stock editor role does not hold it and the admin role holds it implicitly. The select is offered to nobody else at all - the status select's rule, for the status select's reason. The chosen id is checked against the accounts that exist rather than trusted, because `author_id` is a foreign key and an id that is not a user is an exception from the database on save. It is merged into the same `update()` as everything else so one Save is one revision, and it lives in `authorData()` rather than in `contentData()`, which maps boxes to columns and asks nothing about who is asking - which is what lets the preview route reuse it on a controller with no request behind it.

**A weight is a tiebreaker, not a sort order.** `Content::$weight` is a signed whole number and every listing asks for `weight desc, published_at desc` - through `CoreQueries::orderContent()`, one helper behind `content_list`, `content_by_tag` and `content_by_category`, because the agreement is the point: a post that floats on the front page and sits still in its category is a bug nobody would think to look for in the builder they did not change. It orders the featured strip for free, which is what *"featured, and in this order"* means. `content_children` uses it too, with the alphabet underneath instead of a date. **A weight that replaced the date** would mean every new post arriving at 0 and landing at the bottom, which is why it is the tiebreaker and why 0 - every post until somebody says otherwise - is a site ordered exactly as it was. The box in the editor is **validated and not cast**: `(int)` never fails, so `1o` would be 1 and `x` would be 0 with the screen reporting that it saved.

**`status` is not an editable field.** `ContentService::update()` ignores it deliberately — becoming visible sets `published_at` and is what a feed, a cache or a plugin listens for, so it belongs to `publish()` / `unpublish()`. Anything that offers a status control routes through `ContentAdminController::applyPublication()`, which checks `Permissions::forContent($type, 'publish')` and calls those two - or `setPublishedAt()`, for the third case the select cannot express: a post that is already published and whose **date** moved, which is what importing an old post is. That one announces `content:rescheduled` rather than `content:published`, because a listener that mails or pings a feed must not do it again for a date correction. Do **not** put `status` back into `contentData()`: `create()` honours what it is given while `update()` drops it, so the same field published a new post and silently did nothing to an existing one, and neither path asked whether the person may publish. The stock `editor` role holds `post.publish` but not `page.publish`, so the gap was reachable with the default roles.

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

**The logo and the icon are media items, chosen through the picker** (`site_logo`, `site_icon` hold an id). They used to hold a path, on the reasoning that a header logo is chrome: it renders before anything has been uploaded, on pages with no content on them, and deleting a picture must not be able to take the header down. Every one of those is real and **none of them needs a path — they need a fallback.** `AbstractController::brandingAsset()` is it: the chosen item when there is one and it is still there, and `dpress.default_logo` / `dpress.default_icon` when there is not. Never set, deleted, purged and a fresh install all take that one branch, which is the point — there is one way for this to be missing rather than four. Soft-deleted counts as gone: an item somebody put in the bin should leave the header, not wait for a purge.

The defaults are **paths**, resolved by `siteAsset()` against `app.base_url` so they survive the site moving out of a subfolder, and **empty in core** — dpress ships no logo and cannot know what a site keeps in its own `static` folder, so the application sets them in `dpress.ini`. Choosing both costs two extra primary-key lookups per render: measured on the dev site at 33.8 ms against 34.4 ms with neither chosen, which is to say the difference is inside the noise and not worth caching. The alternative was storing a URL, which is the thing `media#<id>` exists to avoid.

**Settings vs config.** `SettingService` reads the database first and falls back to `dpress.ini`. Anything needed *before* the database is reachable — the connection, the JWT secret — stays in the config. Everything an editor may change while the site runs is a setting, and settings are audited.

**A theme is a folder under `themes/` with a `theme.ini`.** Dropping one in installs it; there is no registry. The active theme is a setting, so switching is a runtime action. A setting naming a missing theme falls back to the built-in templates rather than fataling. See [docs/themes.md](docs/themes.md).

**A theme may have a layout per kind of page, and having the file is the registration.** A controller names the kind — `home`, `archive`, `post`, `page`, `auth` — and `AbstractController::layoutFor()` asks the view whether `dpress:layout-<kind>` resolves, which a theme folder answers because it is checked before every namespace folder. No file, and it falls back to `dpress:layout`, which is what makes naming five kinds free: a theme writing one extra file gets two layouts and pays nothing for the other three, and *"category pages read like a post"* is the decision not to write `layout-archive.phtml`. Front-end templates render through **`$layout`**, a variable — nine of them used to say `dpress:layout` outright, so a second layout meant overriding all nine to alter one string. The kind also reaches the template as `$layout_kind`, a class on `<body>`: a second file for markup that differs, a body class for CSS that does. **A kind becomes part of a path, so it is matched against `[a-z0-9_-]+` rather than trusted** — controllers are where kinds come from and a plugin ships controllers too.

**A place only one layout renders is a place that only appears there.** `sidebar` is drawn by the reading layout and `home_top` by the front page's, so a block in one is beside a post and a block in the other is on the front page — with no visibility rule, nothing to configure and nothing for a block author to get wrong. It is the cheap version of a feature that looked expensive, and it arrives as a consequence of two layouts. What it cannot express is a condition finer than which layout.

**Featured posts are a tag, and the strip is the front page's furniture.** `featured_tag` names it (`featured` by default, empty for none), `HomeController` hands the theme `$featured_posts` — at most five, newest first — and passes their ids to `content_list` as **`exclude_ids`** so the same post is not pinned at the top *and* repeated four rows down. A tag rather than a `featured` column because an author already knows how to tag a post and un-featuring is removing one: no new screen, no migration. **`$featured_posts`, never `$featured`** — the single-post templates have used `$featured` for the post's own picture since there were templates. In `applyContentFilters()` the exclusions are one `<>` condition each rather than one `not in`: `Query::nextParamName()` only sees a name once it is **bound**, so asking for several before adding anything hands back the same name every time.

**A date needs a timezone, or it is wrong for a few hours every day.** Every timestamp is stored UTC (`ContentService` writes them with `gmdate()`), which is right - a stored moment should not change meaning when a site moves - and it is exactly why `date_format` could not be added alone: the templates printed the first ten characters of the column, which is the *UTC* day, so a post published at half past midnight in Budapest was dated the day before for everybody. `Dates` is both settings and is set on every render as **`$dates`**; `tag()` writes the whole `<time datetime="...">` so the printed date can read however a site likes while the attribute still says what it means. A timezone that does not exist falls back to UTC rather than throwing, on the missing-theme principle: a typo in a setting must not be an error page on every URL. The same class reads a date **in**: `Dates::parse()` takes `1999-01-02`, optionally with a time, in the site's timezone and returns stored UTC, and `Dates::input()` is the exact reverse - what is shown in the Published box is what would be saved again if nobody touched it. Deliberately not `strtotime()`, which has an opinion about which half of `02/01/1999` is the month and reads trailing rubbish as a modifier; a date that cannot be read comes back as a message on that field and **stops the save**, because `done()` redirects and half a save is worse than none.

**A listing row is an array and carries `featured_media_id`, not the item.** `AbstractController::thumbnails()` is the missing half — `$thumbnails[$post['id']]`, in **one query for the page** via `MediaService::findByIds()`. Without it a theme that wants a card with a picture on it has an id and nothing to do with it, and the obvious workaround is a lookup per row. The mapping is split out as `mapThumbnails()` so it can be tested without a database; a post with no picture and a post whose picture was deleted both come out absent, so `isset()` is the whole check a template writes. **`authors()` is the same shape for `author_id`** and answers with names rather than `User` objects - handing a template the entity hands it an email address and a password hash to print by accident.

**A theme's assets are its own folder**, served at `/assets/theme/<file>` via `$theme->url('style.css')` and cache-busted by the theme's version rather than the CMS's — upgrading dpress should not expire a font nothing touched. `public/static/` would split a theme across two places, so copying one would be copying two. **Flat, no subfolders**: a name matched against `[A-Za-z0-9_-]+\.[A-Za-z0-9]+` cannot climb anywhere, so there is no traversal to reason about, and `url(hero.png)` inside `style.css` resolves correctly for free. The active theme's files only — the name is not in the URL, the same rule a plugin's assets follow.

**`theme:` is the theme's own namespace**, for the templates that are not overrides — the header two layouts share. `dpress:` is for replacing what the CMS ships; a shared partial called `dpress:partial/head` would be a theme claiming a name in the CMS's namespace for a file the CMS does not have. Registered by `ThemeService::apply()`, only while a theme is active.

**A place is one idea, and both editors offer it.** A theme declares `places[] = sidebar`; a menu is assigned to a place, blocks are ordered in one, and `Places::render()` puts the menu first and the blocks after it. Two vocabularies - `places` for menus, `regions` for blocks - would have been a theme author learning two words for one concept and guessing which screen meant which. The built-in layout declares `main` and `sidebar`, and renders blocks in both: a place the editors offer and nothing draws is a promise the site quietly breaks.

**A block is a type plus its settings**, never a column per kind. `type` names something registered in `Blocks` - the registry `Shortcodes` is, with the same `add()` for the core three and for a plugin's - and `settings` is one JSON column holding whatever that type's `fields` asked for. A new kind of block is therefore a registration and never a migration, which is the whole reason it is shaped this way. The type is fixed when a block is made: changing it would leave one type's settings under another type's name.

**The markdown block renders at save**, through the type's optional `prepare` hook, so a page view prints HTML and parses nothing - the `lead_html` rule one level down, and the reason `content:rerender` re-renders blocks too. Shortcodes inside one work with nothing added, because `expand()` runs over the finished page. An unregistered type leaves an HTML comment and a log line rather than throwing: the plugin that provided it may simply be off this morning, and a sidebar with one thing missing still renders. See [docs/blocks.md](docs/blocks.md).

**Blocks are not audited**, like menus (plan §4.4): arranging a layout is moving things about, and a revision per drag is churn rather than history.

**Menu items store a target, not a URL** — `content` / `category` / `tag` / `url` / `home` plus an id — so renaming a page moves its entry with it. `MenuService::tree()` resolves at render time and drops an item whose target is gone. One menu per place: assigning one moves any other out.

**Menus are not audited, settings are** (plan §4.4). A menu editor rewrites the tree wholesale, so its history would be churn.

### Plugins

A folder under `plugins/` with a `plugin.ini` naming a namespace and a class — the theme rule plus the machinery a theme never needs, an autoloader and an entry class. Which are enabled is a **setting**, so it is switchable at runtime and audited. See [docs/plugins.md](docs/plugins.md).

**Most of this was already open**: the two factories' events, `Migrations::add()`, `EntityManager::registerEntity()`, `Permissions::add()`, `FormWidgets::add()`. What 0.23.0 added is a loader, a place in boot to call it from, and a way to serve a plugin's own assets.

**Where it loads is forced from both sides.** After `DpressServices::register()`, because reading the enabled list needs the database — so a plugin cannot replace `Database`, `EntityManager` or `SettingService`. Before `initServices()`, because `Micro::get()` caches singletons forever — so it *can* replace `ContentService` and the rest with a subclass. And before `runMiddlewares()`, which is the one look `AttributeProcessor` takes at the container; a controller registered later has no routes and says nothing about it. **Both apps load plugins** — a plugin registered only on the web path is invisible to `dpress upgrade`.

**`PluginService::load()` never throws.** Enabling lives in the database and the screen that disables a plugin is in the admin, so a plugin that fataled on the way up would take away the only way to turn it off. A failure is caught, recorded on the `Plugin`, and shown as *Failed* on `/admin/plugins`. One breaking does not stop the next. **A failed plugin registers no controllers** — the loader adds those only after `register()` returns, because a live public URL running the code of a plugin that just declared itself broken is the one leftover that is dangerous rather than untidy. `dpress.plugins_off = 1` is the escape hatch for anything subtler.

**Subscribe with a Micro callable, never a closure.** The event service resolves it through the container when the event fires, so an enabled plugin that hooks the content form builds nothing on a page view. That is what keeps the mechanism free: measured at ~1 ms for one enabled plugin and nothing measurable for none.

### The admin

Everything behind `/admin`. **A screen is two actions**: one that renders the page — the filter form, the buttons, the editor, all of which the server decides — and, where there is a list, one that answers with JSON. The rows are what the browser asks for again on every sort, filter and page change.

**Moving between screens does not reload the page.** A link from one admin screen to another fetches the same URL with `?ajax=1`; `admin()` then renders `LAYOUT_PARTIAL` — `views/admin/main.phtml`, the `<main>` element and nothing else — and the browser swaps it in with `outerHTML`. That is `outerHTML` on purpose: it parses in the live document, where an inserted `<script>` stays inert but an `onclick` attribute still works, which is not true of a `DOMParser` document or a `<template>`. **The full layout fetches the same file**, so a partial can never contain something a whole page would not have. What the chrome cannot work out for itself rides on the element as `data-title` and `data-section` — attributes rather than headers, because a title with an accent in it does not survive a header.

**Anything unexpected is a real navigation.** Not ok, redirected somewhere else, or a body that does not start with `<main>`: the browser is handed the URL. Which links get caught at all comes from `data-admin-url` and `data-route-param` on the body, because with `router.use_rewrite` off every screen shares one path and the route is a parameter — and being wrong either way costs a partial load and nothing more.

**Anything a screen leaves outside `<main>` is stale after the first navigation.** That is why the hidden CSRF form is inside it: the token is regenerated and stored on every render, so a form left in the layout would be carrying one the server has already replaced.

`#[Authorize]` sits on **`AbstractAdminController`** and nothing else needs it. That only works because `AttributeProcessor` looks for a class-level attribute along the inheritance chain (micro 0.17.0): PHP attributes are not inherited, and before that fix the base's `#[Authorize]` applied to nothing and every admin screen was reachable anonymously. Each action still checks the permission it actually needs — "may open the admin" and "may delete a user" are different questions.

**The lists render themselves.** `assets/dynamic-list.js` is a rewrite of `dynart-micro-js/dynamic-list.js` with no jQuery and no build step. A list screen is a filter form, a container and one JSON object — no per-screen JavaScript — because `Dpress.list()` takes column views by *name* and row actions as `link` or `post`. Nothing there can be a function; it has been through JSON. **The object is a `data-list` attribute, never an inline `<script>`**, because a screen that arrived as a partial was inserted and inserted HTML does not run its scripts; `Dpress.init(root)` binds whatever it has not bound yet.

**A list screen is one request, not two.** The page seeds `firstPage` into that configuration — the same rows the endpoint would answer — so the table arrives filled and the endpoint is only asked again when a sort, a filter or a page changes. `firstPageContext()` takes the sort from the configuration the browser is about to be primed with, and anything actually in the URL still wins; a seed ordered differently from what the list thinks it is showing would rearrange itself on the first click.

**A column view escapes by default.** `DynamicListColumnView.text` escapes, `html` does not, and the opt out is spelled out at the call site. A post title is whatever somebody typed. `link` escapes its text and its href; `htmlLink` is the pair of it for markup the server built, and escapes only the href.

**A list is for finding things; the editor is for changing them.** There are no per-row Edit or Delete buttons: **the name cell is the link to the editor**, and deleting is selection plus one group action. So `edit_url` is only sent when the person may actually edit — it is the only way in, and the column falls back to plain text without it. Anything left as a row action is something a group cannot express: History, media's Restore (it exists only on a deleted row), and the menus' Rename (whose name cell already opens the items).

**A group action is many things, and some will be refused.** `deleteSelected()` tries each on its own, so one refusal does not abandon the rest, and reports what happened — `2 deleted. Your own account was left alone.` The callback answers `true`, `false` for nothing-to-do, or the sentence to show; a service that refuses by throwing needs no special handling. **`false` is not `true`**: counting a row somebody else already deleted would report more deletions than happened. Group routes are `/delete-selected`, a separate path, because `/delete/?` and `/delete` are two routes and a bulk POST to the first is a 404 *after* the confirm said yes.

**The filter form is the list's state.** Sort, direction, offset and page size live in it as hidden inputs next to whatever the server rendered, so one serialize produces the whole request and a plugin adding a filter field needs to tell the list nothing.

**Never hand a request straight to `addOrderBy()`.** The name goes into the SQL. `ListRequest` drops anything not in the whitelist the screen passes in, and `CoreQueries::applyListOptions()` checks the shape again because a second caller may build the context by hand. The page size is clamped rather than rejected — a browser asking for everything gets a page.

**An action that answers with data must return `AbstractAdminController::answer()`.** `Form::process()` mints a fresh CSRF token on every run and stores it in the session, so validating one action spends the one printed on the page. Page-reloading actions never notice; two AJAX actions in a row do, and the second is refused as a forgery. `answer()` hands the new token back and `Dpress.keepToken()` puts it in the hidden form — from every answer, including a rejected one, which spent the token getting as far as being rejected.

**Deletes and publishes are POSTs.** A link that changes something can be followed by a prefetcher, a crawler or an `<img>` on another page. Every admin screen renders one hidden form carrying a CSRF token, inside `<main>` so a partial load brings a fresh one; `Dpress.post()` points it at the action and submits it. `requireAction()` is what validates it, and a failure is a 403 rather than a redirect with a message.

**The markdown field is a textarea with a toolbar, deliberately not an editor.** A markdown field whose value is anything other than what the author typed eventually rewrites somebody's document on save, and the content model is "the markdown is the truth".

**The sections are a sidebar on the left**, one icon each, and the icon is an inline SVG so it takes the colour of the link it sits in. `--radius` (3px) and `--width` (1280px) in `admin.css` are the two numbers the whole admin is built from; a component with its own corner radius has drifted.

**The admin has its own view namespace, `dpress_admin:`, and it is registered as not themeable.** A theme override otherwise applies to every namespace, so a front-end theme could replace `admin/layout` — not a restyled page, but somebody locked out of their own site. Fetch admin templates through `Dpress::ADMIN_VIEW_NAMESPACE`; a stray `dpress:admin/...` still resolves and is silently themeable again, which is why a test walks every admin file looking for one.

**An icon is `AbstractAdminController::icon('name')`**, which reads `icons/<name>.svg` from the package — a plain file, not a template, because it holds no PHP and the only thing the `.phtml` bought was theme override — and falls back to `section.svg`, so a section or a row action a plugin adds is never invisible for want of a drawing. **Its result is markup**, and that is what an `icon` key means both in the navigation and in a row action, where the list assigns it as `innerHTML`; a row action with no icon falls back to its escaped title. Nothing may build one out of a request.

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

**The schema is one migration, `CreateSchema`.** It was eight until 0.22.0, squashed because dpress is pre-1.0 and no installation holds data anybody minds losing — which also means **every database has to be dropped and recreated** when the schema changes. **After 1.0 this stops** and migrations become append-only.

One ordered list, `CreateSchema::TABLES`, and one call each: whether a table gets an `_aud` mirror is the entity's own answer, since `createTableWithAudit()` builds one only where the class is `#[Auditable]`. The order is the foreign keys' — `Revision` first, because every mirror points at it. Tests guard both halves of what the squash gave up: that every registered entity has a table, and that no child table is built before what it points at.

`Revision` comes from the entities library, so the application's namespace scan does not reach it and `CreateSchema` calls `EntityManager::registerEntity()` for it explicitly.

## Conventions

- **Events**: `<namespace>[.<sub>]:<event_name>` — a single colon, snake_case, past-participle verbs. ORM-level events carry an `entity.` prefix so they cannot collide with service-level ones. See §8 of the plan.
- **Permissions** are plain strings (`post.create`), no table.
- **Forms** must be rendered via `$form->fetch()`, never hand-written `<input>` tags, or plugin-added fields will not appear.
- **All state changes go through a service method**, and every service method emits before/after events. A controller writing an entity directly is invisible to plugins forever.
- **Admin lists** get their sortable columns from a `SORTABLE` constant on the controller, and their rows from a `row()` method that names the fields — an entity handed over wholesale sends `markdown` and `body_html` to the browser on every list request.
