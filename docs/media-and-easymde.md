# Media in the editor, and EasyMDE

**Status: design, not built.** Target: dpress 0.15.0.

The goal, in one sentence: **while writing a post you can insert a picture without leaving the
page** — pick one from the library or upload a new one in a dialog, and the URL lands in the
markdown at the cursor.

Three things follow from that sentence, and each is a decision rather than a mechanism:

1. The markdown field becomes **EasyMDE**, which reverses a decision recorded in the plan.
2. An image inserted into the text becomes an **attachment of that content, marked hidden**, so
   it is not listed twice on the public page.
3. Uploading has to work **inside a dialog**, so the upload endpoint has to answer JSON.

---

## 1. EasyMDE, and the decision it reverses

`CLAUDE.md` and plan §5.9 say the markdown field is *"a textarea with a toolbar, deliberately
not an editor"*, because:

> a markdown field whose value is anything other than exactly what the author typed is a field
> that eventually rewrites somebody's document on save, and the whole content model here is
> "the markdown is the truth".

That reasoning was aimed at **WYSIWYG** editors — the ones that render HTML, let you edit the
render, and convert back to markdown on save. Every round trip through that pair of converters
loses something: a footnote becomes a paragraph, a raw `<figure>` becomes escaped text, a table
loses its alignment row. The document decays under people who never typed a character of
markdown.

**EasyMDE is not that.** It is CodeMirror with a markdown mode: the buffer *is* the markdown,
the preview is one-way, and nothing converts HTML back into anything. What comes out of
`easymde.value()` is what the author typed, character for character. The concern that motivated
the original decision does not apply, and the decision can be reversed without giving up the
content model.

Two things to hold it to, and they belong in the code review rather than the config:

- **No `autosave`.** It writes to `localStorage` under a key and restores it later; a browser
  that restores yesterday's draft over today's edit is precisely the "rewrites somebody's
  document" failure, arriving by a different door.
- **No preview-as-source.** The preview renders through EasyMDE's own marked build, which is
  *not* the CommonMark pipeline the server uses. It is a sketch, never the truth, and the two
  will disagree on footnotes and tables. Label it as a preview and never let it write back.

If either of those is ever wanted, the answer is a server-rendered preview through
`MarkdownRenderer`, which is the only thing that knows what the page will actually look like.

### Shipping it without a build step

dpress has no bundler and should not grow one. EasyMDE is MIT, ships a prebuilt
`easymde.min.js` (~250 KB) and `easymde.min.css` (~15 KB), and needs no compilation.

- Vendor both into `assets/`, exactly like `dynamic-list.js`.
- Add them to `AssetController::ASSETS` so they are served from the package with the version in
  the URL and cached forever. **Bump `Dpress::VERSION` on any asset change** — that is what the
  cache buster is.
- Record the upstream version in `docs/` and in the file header, because a vendored file with
  no provenance is unmaintainable.

**Do not load it on every admin screen.** A quarter of a megabyte on the dashboard, the media
list and the settings page buys nothing. But the layout's `<script>` tags are in `<head>`, and
a screen that arrives through partial navigation cannot add one — inserted HTML never runs its
scripts (see §5 of the 0.13.0 notes).

The way out is a small loader:

```js
Dpress.require = function (url) { /* appends <script> to head, resolves on load, once per url */ }
```

A `<script>` element **created and appended through the DOM does execute** — it is only markup
*parsed* from a string that stays inert. So the editor initialiser can ask for EasyMDE the first
time it sees a markdown field, on a full load and after a partial navigation alike, and the
browser caches it forever after the first editor screen.

### The toolbar icons

EasyMDE's default toolbar uses **Font Awesome** class names. Without Font Awesome the buttons
render as blank squares, and vendoring FA for eight glyphs is absurd.

Pass an explicit `toolbar` array with our own class names, and add a small `easymde-icons.css`
mapping each to a `background-image: url("data:image/svg+xml,...")` built from the icons already
in `views/admin/icon-*.svg.phtml`. Same icon set as the rest of the admin, no new dependency.

The cheap fallback, if that turns out fiddly: keep the current toolbar's text labels (`B`, `I`,
`H`, `“”`). It works and it is honest, just plainer.

### Cleaning up on navigation

The admin replaces `<main>` on every navigation. CodeMirror binds handlers outside the element
it replaces, so the instances have to be told to let go, or every visit to an editor leaves one
behind.

Add a hook to `Dpress.navigate`: before the swap, emit something the initialisers can listen to
(`Dpress.beforeSwap(fn)` or a plain custom event on `document`), and have the EasyMDE
initialiser call `editor.toTextArea()` there. Cheap to add now; miserable to diagnose later as
"the admin gets slow after a while".

---

## 2. `ContentAttachment.hidden`

`views/content/single.phtml` and `page.phtml` both render an **Attachments** list from
`MediaService::attachmentsOf()`. An image that is already visible inside the article must not be
listed again underneath it — so the link exists, and it is marked as not-for-listing.

```php
#[Column(type: Column::TYPE_BOOL, notNull: true, default: false)]
public bool $hidden = false;
```

**On the link, not on the media.** The same library image can be an inline illustration in one
post and a listed download in another; "hidden" is a fact about *this use of it*, not about the
file. Putting it on `Media` would make the two uses fight over one flag.

What reads it:

- `CoreQueries::contentAttachments()` gains `hidden = 0` unless the context asks otherwise, so
  the public list drops them by default and nothing in a theme has to remember to filter.
- `MediaService::attachmentsOf()` keeps its current meaning (what to list) and gains a sibling
  for "everything attached, including hidden" — the admin's media-usage view wants that.
- `MediaService::usageCount()` counts **both**. It is what stops a delete from breaking a page,
  and an inline image breaks a page exactly as thoroughly as a listed one.

### Migration `0008`

`QueryExecutor` can create, drop and rename tables and add a foreign key — it has **no
`addColumn`**. So either:

- **(a)** the migration writes the two `alter table` statements itself (`content_attachment` and
  its `_aud` mirror), which is four lines of MariaDB-specific SQL in a dpress migration; or
- **(b)** `QueryExecutor::addColumn(string $className, string $columnName)` is added upstream in
  micro-entities, built from the same `#[Column]` metadata the `create table` is built from, and
  the migration is one call per table.

**(b)**, and it is the smaller job than it looks: `MariaQueryBuilder` already renders a column
definition for `create table`, so `add column` is that same fragment behind a different prefix.
It is also the last obviously-missing piece of the migration story — plan §"no rename migrations
before 1.0" is about *renames*, and adding a column to a live table is the ordinary case that a
CMS has to do forever. Ship it as micro-entities 0.7.0.

Note the audit mirror has to get the column too, or every audit write after the migration fails
on a column count mismatch.

---

## 3. Attachments follow the text

The dialog can attach on upload — but only when the content already has an id, and a brand new
post does not have one until it is saved. Attaching at upload time also cannot notice when
somebody deletes the image from the text again.

**So reconcile on save instead.** After `ContentService::create()` / `update()` writes the body,
the controller asks a new `MediaService::syncInlineAttachments(int $contentId, string $markdown)`
to make the hidden attachments match what the text actually references:

1. Take the storage's public prefix (`MediaView::urlOfPath('')`) and find every URL in the
   markdown that starts with it — both `![alt](url)` and a raw `<img src>`, since a document is
   allowed to contain HTML.
2. Strip a derivative suffix when the tail matches a known preset from
   `ImageProcessor::presets()` — `photo-a1b2c3-medium.jpg` is `photo-a1b2c3.jpg`.
3. Look the relative path up in `media.path` to get ids.
4. Attach anything referenced and not yet attached, with `hidden = true`.
5. Detach the **hidden** attachments that are no longer referenced. Never touch a visible one:
   somebody added that deliberately in the attachments UI and the article text is not its owner.

This is the design that makes the markdown the truth for attachments too, which is the same rule
the rest of the content model already follows. It also means pasting a URL by hand works exactly
like using the dialog — there is no hidden state that only the dialog knows how to produce.

**One consequence to accept deliberately:** a revision in the history can reference an image the
current text no longer does, and step 5 will drop that link, so `usageCount()` stops protecting
it. Soft delete is unaffected (the file stays), and `media:purge` already says in as many words
that it breaks the revisions that show the file. That is the honest trade; the alternative is
attachments that only ever accumulate, and a library nobody can ever tidy.

Match URLs, incidentally, against the **path** portion only. `app.base_url` may be a full URL
today and a different host tomorrow, and a stored document must not stop resolving because the
site moved — the same reasoning as `siteAsset()` in `AbstractController`.

---

## 4. Uploading from the dialog

`MediaAdminController::upload()` renders a form and redirects on success. The dialog needs an
endpoint that answers **data**:

```
POST /admin/media/upload/json   ->  ['item' => <the same row shape the list returns>]
                                    ['error' => 'Message the dialog can show']
```

- A separate action rather than content-negotiating the existing one. The form path stays
  exactly as it is — it is the no-JavaScript route into the library and should not acquire
  branches — and returning an array is all a dpress controller has to do to send JSON.
- `requirePermission(Permissions::MEDIA_CREATE)` as ever.
- **CSRF: `requireAction()`**, the same hidden `admin_action` token every row action posts with.
  It is already rendered inside `<main>` on every admin screen and already refreshed by partial
  navigation, so the dialog has one without a second mechanism.
- The response row is `MediaAdminController::row()`, unchanged — it already carries `url`,
  `thumbnail_url` and `alt`, which is everything the insert needs.
- An upload that fails validation is a **200 with an `error`**, not a 500. The size limit and
  the type list are ordinary outcomes, and `MediaService::upload()` already throws
  `DpressException` with a sentence meant for a person.

---

## 5. The dialog

`Dpress.pickMedia()` already exists: a `<dialog>`, a search box, and the media list rendered by
`DynamicList` against the same endpoint the library screen uses. That is most of the work
already done, and it stays the mechanism — a filter added to the library shows up in the picker
without anybody wiring it twice.

What it grows:

- **Two panes**, "Library" and "Upload", or a drop zone above the list. Dropping a file, or
  picking one, posts to the JSON endpoint and — on success — hands the new row straight to the
  same callback a chosen row goes to. One code path out of the dialog.
- **Progress** for the upload, from `XMLHttpRequest.upload.onprogress`. `fetch()` cannot report
  it, and a 20 MB image over a phone connection with no feedback reads as broken.
- **The alt text.** An image with no `alt` is invisible to somebody using a screen reader, and
  the moment of insertion is the only moment anybody knows what the picture is *for*. Offer the
  media item's stored `alt` as the default, editable in the dialog, and write a change back to
  the item. Do not silently insert an empty one.
- **`media.create` gates the upload pane**; `media.view` gates the dialog at all. The editor
  screen already knows both — pass them into the view as it passes `can_publish`.

The insert itself is small: `![alt](url)` at the cursor via
`easymde.codemirror.replaceSelection()`, and for a non-image `[file name](url)`, because the
library holds documents too and a PDF is a link, not a picture.

---

## 6. What changes, file by file

**micro-entities (0.7.0)**
- `QueryBuilder::addColumn()` / `MariaQueryBuilder`, `QueryExecutor::addColumn()`.

**dpress (0.15.0)**
- `assets/easymde.min.js`, `assets/easymde.min.css`, `assets/easymde-icons.css` — vendored.
- `src/Controller/Admin/AssetController.php` — the three new names, with content types.
- `src/Entity/ContentAttachment.php` — `hidden`.
- `src/Migration/CreateInlineAttachmentColumn.php` — `0008`, both tables.
- `src/Query/CoreQueries.php` — `contentAttachments()` filters `hidden`.
- `src/Service/MediaService.php` — `syncInlineAttachments()`, `attach()` takes `hidden`,
  `attachmentsOf()` / `allAttachmentsOf()`, `usageCount()` unchanged but documented as counting
  both.
- `src/Controller/Admin/MediaAdminController.php` — `uploadJson()`.
- `src/Controller/Admin/ContentAdminController.php` — call `syncInlineAttachments()` after
  create and update; pass `can_upload_media` into the editor context.
- `assets/admin.js` — `Dpress.require()`, the EasyMDE initialiser, the upload pane, the
  before-swap cleanup hook.
- `views/form-input.phtml` — the markdown field keeps its textarea and gains the data attributes
  the initialiser reads. **It must stay a working textarea with the script disabled**; that is
  the whole reason the field is server-rendered.
- `assets/admin.css` — the dialog's second pane and the drop zone.

**Front end**
- Nothing. `attachmentsOf()` keeps its meaning, so the themes keep working and inline images
  simply stop appearing twice.

---

## 7. Tests

- **PHP unit** — `syncInlineAttachments()` is the piece with real logic and no I/O worth
  mocking: URL extraction (markdown and raw `<img>`), derivative stripping, an unknown URL
  ignored, the same image twice counted once, a **visible attachment left alone**, a hidden one
  dropped when the text stops referencing it. This is the test that matters most in the whole
  feature.
- **PHP unit** — `contentAttachments()` filters hidden by default and includes them when asked.
- **JS** — the insert builds `![alt](url)` and escapes a `]` in the alt text; a non-image
  inserts a link.
- **Live** — upload through the dialog on the dev site, check the row lands in the library, the
  attachment is created hidden, the public page shows the image once and no Attachments list.

The upload endpoint itself wants the integration suite that Phase 6 still owes.

---

## 8. Risks, in the order they are likely to bite

1. **The audit mirror and the new column.** Every audited write goes through the `_aud` table;
   forgetting it there means the *next save of any attachment* fails. The migration writes both
   or neither.
2. **A vendored 250 KB file with no provenance.** Record the version, and never hand-edit it.
3. **EasyMDE and the swapped `<main>`.** Without the cleanup hook this leaks quietly and shows
   up as "the admin feels slow", which is the hardest kind of bug to attribute.
4. **Reconciliation running on content whose markdown was not submitted.** `update()` treats an
   absent key as "leave it alone"; the sync must do the same, or a save from a screen that does
   not carry the body detaches every inline image on the post.
5. **The preview disagreeing with the site.** Covered above: it is a sketch. If anyone asks for
   it to be exact, that is a server round trip, not a bigger client-side renderer.

---

## 9. Order of work

Each step leaves the admin working, and the first three are worth shipping even if the rest
slips.

1. `QueryExecutor::addColumn()` upstream, released as micro-entities 0.7.0.
2. `ContentAttachment.hidden`, migration `0008`, the query filter, `syncInlineAttachments()`
   wired into save. **At this point pasting an image URL by hand already does the right thing**,
   with no JavaScript involved at all.
3. `POST /admin/media/upload/json`, and the upload pane in the existing picker dialog. The
   picker is reachable from the media field today, so this is testable before any editor work.
4. `Dpress.require()` and the before-swap hook.
5. EasyMDE itself, its toolbar, the icon CSS, and the insert button that opens the dialog.

Roughly: 1 is small, 2 is the substantial one, 3 and 4 are small, 5 is a day of fiddling with a
third-party widget and its stylesheet.
