# Changelog

All notable changes to **dpress** are documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/).

---

## [0.25.0] &ndash; 2026-09-02

There is no such thing as an unsaved post.

### Added
- **"New" writes a row and opens the editor for it.** An immediate write needs an id, and being told to save an empty post and come back before you may attach a file to it is a strange thing to be told. `POST /admin/content/<type>/new` calls `ContentService::startDraft()` and redirects to the editor — a POST, not a link, because it inserts.
- `Content::STATUS_AUTO_DRAFT`, **deliberately not in `STATUSES`** — that list is what the status select offers and what `assertStatus()` accepts, so the value cannot arrive from a form or from `content:create`. It is set in one place and left in one other: the first `update()`, which promotes it to a draft and makes the slug from the title.
- `CoreQueries::autoDraft()`, and an exclusion in `applyContentFilters()` so **nothing ever lists one** — the content list, the search, the "Parent page" select, all of them.
- `dpress content:prune [-days 7]`, which throws away the auto-drafts nobody came back to, attachments and all.

### Changed
- **`create()` is gone; `edit()` is the only editor.** That is the real prize: no screen has to answer "and what does this do before the post exists?" ever again. The attachments panel has no empty case, and the first save says *Created.* rather than *Saved.*
- The editor says **New post** while the row has never been saved, and offers no History for it — one revision recording that an empty row was made is not a history.

### Notes
**An auto-draft is reused, not remade.** `startDraft()` hands back the author's existing one for that type, so clicking New five times is one row, a file attached before wandering off is still attached on the way back, and the table is bounded at one row per author per type. That bound is why `content:prune` is a tidy-up rather than a cron.

**The form fills as if the post did not exist** — above all the placeholder slug. Offered back, it would be submitted as though it had been meant, and `auto-draft-3f9c1a2b4d5e6f70` would become the post's URL. Found while writing the change; there is a test for it.

The honest cost is two tabs: open New twice, save the first, and the second tab is now editing the post the first one made. Nothing is lost — every save is a revision — and it is the same surprise as two tabs on one post, which this CMS has never guarded against either.

---

## [0.24.0] &ndash; 2026-09-02

Attachments are files, not an index of the article.

### Changed
- **Putting a picture in the text attaches nothing.** The toolbar's 🖼 button picks a library item and writes `![alt](media#12)`, and that is the whole of it. A reference is all it takes to show a file, so the file need not also hang off the post — which means the button now works on a post that has **never been saved**, where it used to be disabled with an apology.
- **Attachments are the files somebody attached on purpose**, listed under the published page. "Add attachment" is the only thing that attaches.
- `MediaService::usageCount()` counts `media#<id>` in `markdown` as well as the attachment and featured rows, so deleting a picture an article shows still warns about it. A `like` candidate count on the same terms as `ContentService::referrerIds()`, and it counts **distinct content**: a post that attaches a file and shows it in the body is one thing that breaks, not two.
- An empty `noResults` in a list configuration now renders nothing at all rather than an empty box.

### Removed
- **`ContentAttachment.hidden`**, with `MediaService::setAttachmentHidden()` and `allAttachmentsOf()`, the `with_hidden` context on `contentAttachments()`, the **On the page** column, the Hide/Show row actions and `POST /admin/content/?/attachment-visibility/?`. The flag existed because inserting a picture used to attach it and an attached picture already in the article should not be listed under it as well; with nothing attached there is nothing to hide.
- The paragraph above the attachment list explaining all of that, and "Nothing attached yet." On a post that does not exist yet the panel does not render at all, instead of a heading and a disabled button that explain themselves away.

### Notes
**A distinction instead of a flag.** One table held two different things — files somebody attached, and images the text happens to show — and a boolean kept them apart. They are now two mechanisms that share nothing but the picker dialog, and the editor's list and the public list ask the same question and get the same answer.

The one thing genuinely lost was that an inline image no longer has a row saying it is in use, which is what `usageCount()` now goes to the markdown for.

**The `hidden` column goes out of `CreateSchema` rather than through a migration**, which needs the database dropped and recreated — the licence that holds until 1.0.0.

---

## [0.23.0] &ndash; 2026-08-06

Plugins.

### Added
- **A plugin is a folder under `plugins/` with a `plugin.ini` in it** — the theme rule, plus the two things a theme never needs: a namespace to autoload and a class to build. `AbstractPlugin` gives a no-op default to every contribution, so one that adds a single field type is four lines. See [docs/plugins.md](docs/plugins.md).
- **A plugin may contribute** services, controllers (routes come from their attributes by themselves), entities, migrations, form widgets, view folders, admin assets and permissions — plus `register()` for events and the two factories.
- **`/admin/plugins`**, behind `plugin.manage`, with Enable/Disable as group actions. Plus `plugin:list`, `plugin:enable`, `plugin:disable`.
- **`Dpress.addInit(fn)`**, so a plugin's widget can bind behaviour and have it run again after a partial navigation, and `/admin/assets/plugin/<name>/<file>` to serve its `.js` and `.css` — from its own folder, only while it is loaded, and only for a name with no slashes in it.
- `plugins/reading-time/` in the development app: an example exercising every extension point at once, which is what the whole thing is tested against.

### Notes
**Where the loader sits is forced from both ends.** After `DpressServices::register()`, because reading the enabled list needs the database, so a plugin cannot replace `Database` or `SettingService`. Before `initServices()`, because `Micro::get()` caches singletons forever, so it *can* replace `ContentService` with a subclass. And before `runMiddlewares()`, which is the single look `AttributeProcessor` takes at the container.

**Nothing in the loader throws.** Enabling is a setting and the screen that disables a plugin is in the admin, so a plugin that fataled on the way up would take away the only way to turn it off. Failures are caught and shown as *Failed* with their message; one breaking does not stop the next; an enabled plugin that is gone from disk is *Missing*; a database with no `setting` table yet simply has no plugins, so `dpress install` runs. `dpress.plugins_off = 1` boots with none of them.

**A failed plugin registers no controllers.** Found by testing it: the first version registered them before `register()` ran, so a plugin that threw left its routes live — a public URL running the code of a plugin that had just declared itself broken. They now go in only after `register()` returns. The container cannot unregister anything, so its widgets and permissions do remain; those are untidy rather than dangerous, and its entity and migration remaining is what keeps its table from being dropped out from under its data.

Measured, because `docs/performance.md` claimed "no plugin API to boot" as a reason dpress is fast and that stopped being true: **one enabled plugin costs about 1 ms, none costs nothing measurable.** The document now says so with the numbers.

**Needs micro 0.20.0.**

---

## [0.22.0] &ndash; 2026-08-06

The schema is one migration again.

### Changed
- **The eight migrations are squashed into `CreateSchema`** (`0001_create_schema`). Eight files describing a schema nobody had ever applied incrementally were eight files to read to find out what a table looks like, and the `alter` that added `ContentAttachment.hidden` is now just a column on the entity. One ordered list of tables, one call each — whether a table gets an audit mirror is the entity's own answer, because `createTableWithAudit()` builds one only where the class is `#[Auditable]`.

### Notes
**Every existing database has to be dropped and recreated.** That is deliberate and it is a pre-1.0 licence: dpress is on no public domain holding data anybody minds losing. **After 1.0 this stops** and migrations become append-only.

Verified by installing the squashed schema into a scratch database and diffing it against the real one: byte-identical except that `hidden` now sits where the entity declares it rather than appended at the end, which is cosmetic in SQL. The seeded roles and the editor's 23 permissions are identical too. The dev database was then rebuilt on it, with its content dumped and restored — 6 posts and pages, 6 media, 4 users, 3 menu items, 6 attachments, 82 revisions, all matching the dump row for row.

Three new tests guard what the squash gave up. While the schema was eight files, each one listed the group it created and the pairing was obvious from the file you were editing; in one list an entity can be registered, work, and only turn out to have no table on somebody else's fresh install. So: every registered entity has a table, nothing has a table that is not a registered entity, `Revision` is built first because every `_aud` mirror points at it, and no child table is built before what it points at.

---

## [0.21.0] &ndash; 2026-08-06

### Changed
- **The CMS field types are registered, not branched.** `markdown`, `media`, `checkboxes` and `permissions` now go through micro 0.20.0's `FormWidgets`, one template each under `views/widget/`, registered by `DpressServices::registerWidgets()` — which is exactly the call a plugin will use. `DpressForm::VIEW_INPUT` and `views/form-input.phtml` are deleted.

### Notes
That constant was a mechanism with room for **exactly one** contributor. The framework offered a single override for "the template that renders a field", the CMS spent it, and after that nothing could add a fifth field type without forking the CMS's own template and re-implementing all four branches. The registry has room for everybody, and the core now registers its types the same way a plugin does — a mechanism the core does not eat is a mechanism nobody notices breaking.

Verified against the running site: every widget renders on eight admin screens plus the two public forms, and nothing anywhere reports an unregistered type.

**Needs micro 0.20.0.**

---

## [0.20.1] &ndash; 2026-08-06

### Added
- **A `#` column on every admin list**, showing the row's id. It is what a reference in somebody's markdown is made of — `post#42` and `media#12` are written by hand as often as they are inserted by a button, and until now the only place to read an id off was the URL of the edit page.

### Notes
Sortable where the list actually sorts: `id` was added to the five sort whitelists, and `AdminTest::assertSortableAgainst` checks each of those names is a real column of the entity. The roles and the menus lists answer from a `page()` that takes no sort at all, so the column is declared unsortable there rather than given a header that does nothing.

None of the list queries join, so an unqualified `order by id` is unambiguous — worth checking, because `tag_cloud` does join and both of its tables have an `id`.

Verified against the running site: the column present and first on all eight lists, and sorting by it both ways on the five that sort.

---

## [0.20.0] &ndash; 2026-08-06

The list is for finding things. The editor is for changing them.

### Added
- **"Delete selected" on every list**, with the checkbox column the list has always been able to draw. Each row is tried on its own, so **one refusal does not abandon the rest**, and the page says what happened: `2 deleted. Your own account was left alone.` The same reason twice is one sentence. A row somebody else deleted in the meantime is not counted — reporting more deletions than happened is how a count stops being worth reading.
- `AbstractAdminController::deleteSelected()`, which every list's endpoint goes through. The rules stay with the controller that owns them: the callback answers `true`, `false` for nothing-to-do, or the sentence to show. Anything it throws is read as the third, so `UserService`'s last-administrator guard and `RoleService`'s protected roles need no special handling.
- A `htmlLink` column view, the counterpart of `link` for markup the server built — `link` escapes its text, which is right for a name and wrong for a thumbnail.

### Changed
- **The per-row Edit and Delete buttons are gone from every list.** The name cell was already a link to the editor in most of them; the rest were made to match. Deleting is selection plus one button.
- **Publish and Unpublish are gone from the content list too.** Status belongs to the editor's own control, which is where it can be seen next to what it applies to. The `/publish/` and `/unpublish/` endpoints stay — they are a reasonable thing for a script to POST to.
- **The media list's File name opens the item, not the file.** It is the only list where the name led somewhere else. The thumbnail is now the link to the file, so nothing became harder to reach.
- **`edit_url` is only sent when the person may edit.** It used to travel with every row while the button next to it was permission-checked, so the link was the loose one — and it is now the only way in. The column falls back to plain text without it. The administrator role, which is not editable, gets no link either.
- The menus list keeps its **Rename** button. Its name cell opens the items, which is what there is to edit about a menu, and that leaves renaming with no cell of its own.

### Notes
Media keeps **Restore** as a row action. It is the one thing here that is not a bulk operation: it only exists on a deleted row, and finding those is the work.

Group routes are `/delete-selected` rather than the single route with no id, because `/delete/?` and `/delete` are two different routes and a bulk POST arriving at the first would be a 404 after the confirm dialog said yes. `AdminTest` gained a test that all seven exist, and the "state-changing routes must be POST" test was fixed — its pattern matched `/delete/` and quietly skipped every one of the new ones.

Verified against the running site with a throwaway administrator account, since these are all POSTs behind a session: every list's configuration read back from the rendered page, a mixed selection of a real row and a missing one reporting `1 deleted.`, the self-delete guard, and the protected `admin` role surviving with its reason shown. The account and the test rows were removed afterwards.

---

## [0.19.0] &ndash; 2026-08-06

A document says what it points at, not where that is today.

### Added
- **Internal references in markdown: `media#12`, `post#42`, `page#5`, `category#21`, `tag#7`.** Written in a link or an image destination, they become the finished URL when the markdown is rendered. The stored document never mentions a hostname or a slug, so moving a site from a test domain to a real one is a change to `app.base_url` plus `dpress content:rerender`, and no stored markdown changes at all. `post`, `page` and `content` are one lookup — ids are unique across both types and the entity decides the shape of its own URL, so `post#5` naming a page still resolves. A trailing `#anchor` or `?query` is carried over.
- **`MarkdownRenderer::EVENT_ENVIRONMENT`.** The CommonMark environment is offered to subscribers before the converter is sealed, which is how the CMS reaches the renderer without the renderer knowing the CMS exists. It is also where a plugin would add tables.
- **The attachments panel shows the reference** in a Reference column, so it can be read off the row and typed by hand.
- **`TaxonomyService::categoryPath()` / `tagPath()`**, so `/category/<slug>` has one home rather than being spelled out wherever it was needed. `MenuService` uses them.
- `docs/internal-links.md`.

### Changed
- **The insert button writes the reference, not the URL.** Both writers — the toolbar's 🖼 button and the attachment row's insert action — go through `Dpress.insertMedia()`, which now produces `![alt](media#12)`. Documents written before this still hold absolute URLs and go on working as ordinary links; nothing converts them, because that would be rewriting everybody's documents to fix a problem they may not have.
- **A rename re-renders what links to it.** `ContentService::update()` notices when a slug or a `parent_id` moved and re-renders every document whose markdown mentions that id. A page moves more than itself, so its descendants count as moved too. The candidate query is a `like`, which also matches `post#421` — re-rendering something that did not need it produces the same bytes, and no amount of SQL is going to parse markdown.
- `MarkdownRenderer` builds its converter from an `Environment` rather than the `CommonMarkConverter` shorthand. Same two options, same output; the existing "raw HTML is stripped" and "`javascript:` is refused" tests are what proves it.

### Notes
A reference whose target is gone **unwraps the node, keeping its children**: `[the old post](post#42)` becomes the words, `![Screenshot](media#12)` becomes the alt text. One operation covers both, because an image's alt text *is* its children. The alternative leaves `media#12` in a `src`, which is a broken image on a published page — the visitor pays for the editor's deleted file.

The rewrite works on the parsed document, not on the rendered HTML. A URL is a field of a node there and unwrapping is two calls; on a string both are regular expressions over markup, and the second would have to match a whole `<a>` with whatever is nested inside it.

`LinkTargets` keeps no answers, and that is load-bearing. It memoised them at first and was wrong inside one request: a rename re-renders its referrers in the same request as the rename, and every one of those renders was handed the URL from *before* the slug changed — the rename appeared to do nothing. Found against the running site, not in a test. The dedup that is safe, one picture twice in one document, sits in `InternalLinks`, which knows where a document ends. `LinkTargetsTest::testAnswersAreNeverKept` fails if the cache comes back.

Verified against the running dev site: the same markdown rendered under two different `app.base_url` values, a post renaming propagating into a referrer's cached HTML, and a page rename reaching a post that links to that page's *child*. No migration.

---

## [0.18.0] &ndash; 2026-08-05

The admin is not a theme's to change.

### Changed
- **Admin templates moved to their own view namespace, `dpress_admin:`, registered as not themeable.** A theme overriding one template used to reach *every* template there is — including `admin/layout`, which is what the way in is rendered with. A theme restyling the front end is the whole point of themes; a theme replacing the admin's layout is somebody locked out of their own site. The front end is unchanged and as themeable as ever.
- **The admin icons are plain `.svg` files** in `icons/`, read from disk rather than rendered as templates. They contain no PHP, so calling them templates bought exactly one thing: a theme could replace them.

### Notes
Outside `views/` because they are not templates, and outside `assets/` because nothing serves them over HTTP — they are inlined so the drawing takes the colour of whatever it sits in. `AbstractAdminController::icon()` keeps its per-request memoisation and its fallback to `section.svg`, so a section or row action a plugin adds is still never invisible for want of a drawing.

The menu items screen took its two icons by fetching them itself; they come from the controller now, which is how every list already gets its icons.

Two tests guard the lock: one that no admin file reaches a template through the themeable namespace — a single `dpress:admin/...` left behind would still resolve, and would silently be themeable again — and one that no icon is a template. Verified against the running site with a theme that tried to replace both layouts: the front end was hijacked, the admin was not.

Needs micro 0.19.0.

---

## [0.17.1] &ndash; 2026-08-05

### Fixed
- **The second action of any pair was refused as a forgery.** `Form::process()` mints a fresh CSRF token every time it runs and stores it in the session, so validating one action spends the token printed on the page. That is invisible while every action reloads the page — the new page carries the new token — and fatal for two actions without one in between, which is exactly what uploading a file and then attaching it is. The upload succeeded, the attach was refused, and the message blamed the attach: *"That file could not be attached."* An action that answers with data now hands the new token back, and the browser puts it in the hidden form.

### Notes
It was not only the upload. **Any two panel actions in a row hit it** — hide then detach, attach then attach — because each POST spent the token the last one left. Only the first click after a page load worked.

Rotation is kept rather than removed: a token that leaked out of one response is spent. `AbstractAdminController::answer()` is now the one way an action returns data, so a new one cannot forget the token and leave its second click failing. The browser adopts it from **every** answer including a rejected upload, which validated the token to get as far as being rejected.

---

## [0.17.0] &ndash; 2026-08-05

Uploading from inside the picker, so a file can be added without leaving the editor.

### Added
- **`POST /admin/media/upload/json`** — the same upload as the form, answering with the row instead of a redirect.
- **An upload pane in the media picker**: drop a file on it or choose one, with a progress bar. What lands goes straight to the callback the dialog was opened with, which is the same one a chosen row goes to — so uploading and picking are one path out of the dialog and nothing that opens it has to know which happened.

### Notes
**A separate action rather than a branch inside `upload()`.** That one stays exactly what it was: the way into the library for somebody with no JavaScript, and not a place to grow two behaviours. The dialog cannot follow a redirect anyway — the point of it is that the half-written post behind it is still there afterwards.

**A rejected file is a 200 with an `error`.** Too large, or a type this site does not accept, are ordinary answers to an ordinary request, and `MediaService::upload()` already throws them with a sentence meant for a person. A 500 would claim the server broke and leave the dialog nothing useful to show. Verified: an unaccepted type answers `{"error":"Files of type 'application/octet-stream' are not accepted."}` with status 200, a missing token is a 403, and a missing file says so.

**`XMLHttpRequest`, not `fetch`.** For the one thing it still does that `fetch` cannot: report upload progress. A large photo over a slow connection with no feedback reads as broken, and somebody presses the button again.

The pane is only rendered where `media.create` is held — the endpoint checks it too; the attribute only keeps a useless control off the screen.

---

## [0.16.1] &ndash; 2026-08-05

### Fixed
- **The featured image never showed its thumbnail.** The media field's preview was rendered from a `$media_preview` view variable that **nothing anywhere set**, so a field with an image chosen showed an empty box - the id was there, the Remove button was there, the picture was not. The preview is now part of the field definition, rendered by the form builder from the controller's context.

### Notes
On the field rather than in a view variable, because one variable cannot be the preview of two media fields on the same form - and a category thumbnail next to a featured image is the obvious second one. A media id that no longer resolves shows no preview rather than failing: the field still holds the id, so the editor can see it is set and change it.

---

## [0.16.0] &ndash; 2026-08-05

The attachments panel, in the content editor.

### Added
- **An attachments list under the markdown field**, with the three things an author needs: **attach** a library item, **detach** it, and **hide or show** it — hidden meaning attached but left off the list at the bottom of the published page, which is what an image inside the article wants. There is also a row action that writes the file into the body at the cursor.
- **A button on the markdown toolbar** that picks a file, attaches it **hidden**, and inserts it into the text in one go — because a picture that is in the article should not be listed under it as well.
- Endpoints for all of it, and `Dpress.send()`: the same POST as a row action but over `fetch`, so an editor never reloads and loses what has been typed. Row actions can now be declared `ajax` (post and refresh the list) or `insert` (write into the field), next to the existing `link` and `post`.

### Removed
- **The automatic reconciling of attachments against the markdown**, added in 0.15.0. It fights the author, which is the whole reason the panel exists: detaching a row would re-attach it on the next save, and a hidden flag set by hand would be overwritten. **Removing a file from the text and detaching it are two separate acts, and both are the author's.**

### Notes
`ContentAttachment.hidden` and migration `0008` are unchanged — what moved is who decides. Nothing infers an attachment from the text any more, and nothing infers the text from an attachment.

**The panel writes immediately rather than on Save.** One write model, the same as every other row action in the admin. That is also why it needs a saved post: a new one has no id to attach to, so the panel says so and the buttons are inactive rather than pretending. An abandoned form then cannot leave files attached to nothing either.

The panel sits **outside** the editor's `<form>`. Its controls are buttons; nested inside they would submit the form, and their state would look like something Save is responsible for.

---

## [0.15.0] &ndash; 2026-08-05

An image in the article body is attached to it, and not listed twice.

### Added
- **`ContentAttachment.hidden`** and migration `0008`. An image inside the body is attached — so "is this file still used" has a true answer and a delete says what it affects — but it is already on the page, and listing it again under "Attachments" says the same thing twice.
- **`MediaService::syncInlineAttachments()`**, called after every content save. It reads the body, resolves the storage URLs in it back to library items, and makes the hidden attachments match.
- `MediaService::allAttachmentsOf()` for the places that want the true picture rather than the published one, and `referencedMediaIds()` for anything else that needs to ask what a body points at.

### Notes
**On the link, not on the media.** The same library image can be an inline illustration in one post and a listed download in another, so hidden is a fact about *this use* of it. On `Media` the two uses would fight over one flag.

**Attaching happens on save, not on upload.** A new post has no id until it is saved, so there is nothing to attach to at upload time — and attaching then would never notice the image being deleted from the text again. Reconciling on save makes the markdown the truth for attachments too, which is the rule the rest of the content model already follows, and it means **pasting a URL by hand behaves exactly like using the picker**. There is no hidden state that only a dialog knows how to produce.

**Visible attachments are never touched.** Somebody added those deliberately, to be listed; the article text is not their owner and must not remove them for going unmentioned.

Matched on the **path**, so a full URL and a bare one resolve to the same file — `app.base_url` may be a different host tomorrow, and a stored document must not stop resolving because the site moved. A `-thumb` / `-medium` / `-large` suffix is stripped when it names a preset this installation actually has, so `notes-draft.txt` keeps its name. `usageCount()` counts hidden attachments too: an image inside a body breaks the page exactly as thoroughly as a listed one.

Needs micro-entities 0.7.0 for `addColumnWithAudit()`.

---

## [0.14.4] &ndash; 2026-08-05

### Security
- **A dpress site no longer writes its logs into the document root.** `Logger`'s default directory is the relative `logs`, resolved against the working directory — which for a web request is `public/`. So a site that configured no log directory served its own error log, complete with stack traces, absolute filesystem paths and bound SQL parameters, at `/logs/log_2026-08-05.txt`. `DpressLogger` defaults to `~/logs`, one level above what Apache serves, and both apps register it before the logger is built.

### Notes
The dangerous option should not be the one you get by saying nothing. A site that wants its logs somewhere else still sets `log.dir`; a site that sets nothing is now safe by default.

**Check your own installation.** If `public/logs/` exists, everything in it has been readable by anybody who guessed the filename: delete the directory after upgrading, and treat anything an error mentioned — paths, queries, parameters — as having been public.

Needs micro 0.18.1, where the log directory finally goes through `getFullPath()` so `~/logs` means the site root rather than a folder called `~`.

---

## [0.14.3] &ndash; 2026-08-05

### Fixed
- **The last administrator could be locked out of their own site.** Setting them to Blocked, taking the admin role away in the user editor, or deleting the account all went through unguarded, and `AuthService::login()` refuses anybody who is not active — so the site was left with nobody who could sign in. Recovering needed shell access, which is exactly what the person locked out of their own admin does not necessarily have.

### Notes
The check existed, but only in `UserCommands`: it guarded `dpress user:role -revoke` and nothing else, while the admin UI revoked through `applyRoles()`, blocked through `setStatus()` and deleted through `delete()`. A rule about the state the **site** may end up in belongs in the service every path has to go through, so it moved to `UserService` and now covers all three, in the UI, on the command line, and for anything a plugin calls.

**The rule counts *active* administrators**, not accounts holding the role. With two of them and one already blocked, blocking the other leaves nobody who can sign in while a naive count still says two. By the same reasoning an account that is already blocked is not protected: taking the role from somebody who cannot sign in anyway costs the site nothing.

Pending counts as locked out, the same as blocked — `login()` makes no distinction, so neither does this.

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
