# Media in the editor

**Status: built** (micro-entities 0.7.0, dpress 0.15.0 through 0.17.0), with one part of the
design withdrawn. §3 — reconciling attachments against the markdown — was built and then
removed: **the attachments are the author's, and nothing recalculates them.** The panel in §4
replaces it.

The goal, in one sentence: **while writing a post you can insert a picture without leaving the
page** — pick one from the library or upload a new one in a dialog, and the URL lands in the
markdown at the cursor.

Three things follow from that sentence, and each is a decision rather than a mechanism:

1. An image inserted into the text becomes an **attachment of that content, marked hidden**, so
   it is not listed twice on the public page.
2. Uploading has to work **inside a dialog**, so the upload endpoint has to answer JSON.
3. The markdown field stays **exactly what it is** — a textarea with a toolbar — and gains one
   more button.

---

## 1. The editor stays a textarea

Plan §5.9 and `CLAUDE.md` say the markdown field is *"a textarea with a toolbar, deliberately
not an editor"*, because a field whose value is anything other than what the author typed
eventually rewrites somebody's document on save, and the content model here is that the markdown
is the truth. **That decision stands.** It was reconsidered while planning this and kept.

What settled it was the preview. Any editor widget that renders markdown in the browser renders
it with a *different implementation* than the one that builds the page, and this site's server
side is configured in ways no client-side renderer will match:

- `html_input => 'strip'` — the server removes raw HTML. A browser renderer shows it. Embed an
  iframe, watch it work in the preview, watch it vanish on publish.
- Plain CommonMark, no GFM extension — **the server does not render tables.** Most browser
  renderers default to GFM and do. Write a table, see a table, publish pipe characters.
- The `---` lead/body split is dpress's own rule, and no third-party renderer knows it exists.
- `MarkdownRenderer::setConverter()` lets a plugin swap the converter entirely, so on such a
  site a client-side preview is wrong by construction.

A preview that lies costs somebody an afternoon. Not having one costs nothing, because the
markdown *is* what they are editing.

**If a preview is ever wanted, it comes from the server** — `POST /admin/content/preview`
through the real `MarkdownRenderer`, returning the lead and body HTML the page will actually
show. That is the only kind that can be trusted. Out of scope here; noted so the next person
does not have to rediscover why.

So the whole editor-side change is: **one more button on the existing toolbar.**

`MARKDOWN_ACTIONS` in `admin.js` already drives that toolbar, and `replaceSelection()` already
knows how to put text at the cursor. The new button opens `Dpress.pickMedia()` and inserts what
comes back:

- an image → `![alt](media#<id>)`
- anything else → `[file name](media#<id>)`, because the library holds documents too and a PDF is
  a link, not a picture

`]` in the alt text has to be escaped, or the link breaks.

> **The destination changed in 0.19.0.** It used to be the finished URL, which put this site's
> hostname inside every document that had a picture in it. It is now a reference, resolved when
> the markdown is rendered — see [internal-links.md](internal-links.md). Everything else on this
> page still holds.

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

## 3. Attachments follow the text — *withdrawn*


> **This was built in 0.15.0 and removed in 0.16.0.** It reads well and it is wrong: it fights
> the author. Detaching a row would re-attach it on the next save, because the text still
> mentions the file; a hidden flag set by hand would be overwritten by whatever the text implied.
> Two things can own the attachment list, and they cannot both be right.
>
> The author owns it. Removing a file from the text and detaching it are separate acts, and the
> panel below is how the second one is done. Kept here because the reasoning is worth having when
> somebody proposes it again.

### The design that was withdrawn, for the record

The dialog can attach on upload — but only when the content already has an id, and a brand new
post does not have one until it is saved. Attaching at upload time also cannot notice when
somebody deletes the image from the text again.

**So reconcile on save instead.** After `ContentService::create()` / `update()` writes the body,
the controller asks a new `MediaService::syncInlineAttachments(int $contentId, string $markdown)`
to make the hidden attachments match what the text actually references:

1. Take the storage's public prefix (`MediaView::urlOfPath('')`) and find every URL in the
   markdown that starts with it — both `![alt](url)` and a raw `<img src>`, since a document is
   allowed to contain HTML even though the renderer will strip it.
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

## 4. The attachments panel

Under the markdown field, outside the editor's `<form>`, a `DynamicList` of everything attached
to this content — hidden included, since the whole point is to be able to un-hide from it.

| Row action | Does |
|---|---|
| **Insert** | writes `![alt](url)` into the textarea at the cursor. Client side only |
| **Hide** / **Show** | flips `hidden`. Two actions with `visibleWhen`, not a toggle — the same pattern as publish/unpublish |
| **Detach** | removes the link. **Leaves the text alone**, and says so in the confirmation |

Plus **Add attachment**, which picks a library item and attaches it *visible*, and the toolbar's
button, which picks one, attaches it *hidden*, and inserts it into the body.

Those two differ in exactly two ways and share everything else. Hidden-and-inserted is one act
because a picture in the article should not be listed under it as well; visible-and-not-inserted
is the other because that is what an attachment list is for. **Neither decides anything
afterwards.**

**Everything writes at once, over `Dpress.send()`** — the same POST a row action makes, sent with
`fetch` so the editor is never reloaded and nothing typed is lost. One write model, the same as
the rest of the admin. It is also why the panel needs a saved post: there is no id to attach to
before that, so it says so and the buttons are inactive rather than pretending.

`Dpress.send()` also gave the list two new row action kinds next to `link` and `post`: **`ajax`**
(post, then refresh the list) and **`insert`** (write into the field). Both are declared as data,
so a plugin adding one is still adding an array rather than code.

---

## 5. Uploading from the dialog

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

## 6. The dialog

`Dpress.pickMedia()` already exists: a `<dialog>`, a search box, and the media list rendered by
`DynamicList` against the same endpoint the library screen uses. That is most of the work
already done, and it stays the mechanism — a filter added to the library shows up in the picker
without anybody wiring it twice.

What it grows:

- **An upload pane** — a drop zone and a file input above the list. Dropping a file, or picking
  one, posts to the JSON endpoint and — on success — hands the new row straight to the same
  callback a chosen row goes to. One code path out of the dialog.
- **Progress**, from `XMLHttpRequest.upload.onprogress`. `fetch()` cannot report it, and a 20 MB
  image over a phone connection with no feedback reads as broken.
- **The alt text.** An image with no `alt` is invisible to somebody using a screen reader, and
  the moment of insertion is the only moment anybody knows what the picture is *for*. Offer the
  media item's stored `alt` as the default, editable in the dialog, and write a change back to
  the item. Do not silently insert an empty one.
- **`media.create` gates the upload pane**; `media.view` gates the dialog at all. The editor
  screen already knows both — pass them into the view as it passes `can_publish`.

The dialog is built and thrown away per use and lives outside `<main>`, so partial navigation
needs nothing from it either way.

---

## 7. What changes, file by file

**micro-entities (0.7.0)**
- `QueryBuilder::addColumn()` / `MariaQueryBuilder`, `QueryExecutor::addColumn()`.

**dpress (0.15.0)**
- `src/Entity/ContentAttachment.php` — `hidden`.
- `src/Migration/AddHiddenToContentAttachment.php` — `0008`, both tables.
- `src/Query/CoreQueries.php` — `contentAttachments()` filters `hidden`.
- `src/Service/MediaService.php` — `syncInlineAttachments()`, `attach()` takes `hidden`,
  `attachmentsOf()` / `allAttachmentsOf()`, `usageCount()` unchanged but documented as counting
  both.
- `src/Controller/Admin/MediaAdminController.php` — `uploadJson()`.
- `src/Controller/Admin/ContentAdminController.php` — call `syncInlineAttachments()` after
  create and update; pass `can_upload_media` into the editor context.
- `assets/admin.js` — the image button in `MARKDOWN_ACTIONS`, the insert, the upload pane in
  `pickMedia()`.
- `assets/admin.css` — the dialog's upload pane and drop zone.
- No new assets, no new dependency, no build step. **Bump `Dpress::VERSION`** anyway, because
  `admin.js` changed and the URL is the cache buster.

**Front end**
- Nothing. `attachmentsOf()` keeps its meaning, so the themes keep working and inline images
  simply stop appearing twice.

---

## 8. Tests

- **PHP unit** — `syncInlineAttachments()` is the piece with real logic and no I/O worth
  mocking: URL extraction (markdown and raw `<img>`), derivative stripping, an unknown URL
  ignored, the same image twice counted once, a **visible attachment left alone**, a hidden one
  dropped when the text stops referencing it. This is the test that matters most in the whole
  feature.
- **PHP unit** — `contentAttachments()` filters hidden by default and includes them when asked.
- **JS** — the insert builds `![alt](url)`, escapes a `]` in the alt text, and produces a plain
  link for a non-image.
- **Live** — upload through the dialog on the dev site, check the row lands in the library, the
  attachment is created hidden, and the public page shows the image once with no Attachments
  list under it.

The upload endpoint itself wants the integration suite that Phase 6 still owes.

---

## 9. Risks, in the order they are likely to bite

1. **The audit mirror and the new column.** Every audited write goes through the `_aud` table;
   forgetting it there means the *next save of any attachment* fails. The migration writes both
   or neither.
2. **Reconciliation running on content whose markdown was not submitted.** `update()` treats an
   absent key as "leave it alone"; the sync must do the same, or a save from a screen that does
   not carry the body detaches every inline image on the post.
3. **Derivative URLs.** An author who picks the medium size gets `-medium` in the URL, and a
   reverse lookup that does not strip it finds no media and attaches nothing. Silent, and only
   visible later as "deleting this image did not warn me it was in use".

---

## 10. Order of work

Each step leaves the admin working, and the first two are worth shipping even if the rest slips.

1. ~~`QueryExecutor::addColumn()` upstream~~ — **done**, micro-entities 0.7.0, as
   `addColumnWithAudit()` so the mirror cannot be forgotten.
2. ~~`ContentAttachment.hidden`, migration `0008`, the query filter, `syncInlineAttachments()`
   wired into save.~~ — **done**, dpress 0.15.0. Pasting an image URL by hand now does the right
   thing, with no JavaScript involved at all.
3. `POST /admin/media/upload/json`, and the upload pane in the existing picker dialog. The
   picker is reachable from the media field today, so this is testable before touching the
   editor at all.
4. The image button on the markdown toolbar.

Steps 1, 3 and 4 are small. Step 2 is the substantial one, and it is the one worth being
careful with.
