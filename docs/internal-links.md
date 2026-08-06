# Internal links

**Status: built** (dpress 0.19.0).

The goal, in one sentence: **a stored document says what it points at, never where that is
today.**

```markdown
![A sunset](media#1)
See [the welcome post](post#1), filed under [news](category#3).
```

becomes, when the page is rendered:

```html
<img src="https://example.com/uploads/2026/08/sunset-photo-e6dd2d.jpg" alt="A sunset" />
See <a href="https://example.com/post/welcome-to-dpress">the welcome post</a>,
filed under <a href="https://example.com/category/news">news</a>.
```

Nothing in the `markdown` column mentions a hostname or a slug. Moving a site from a test domain
to a real one is a change to `app.base_url` and one command; renaming a post is a rename.

---

## 1. The form

```
<kind>#<id>[<#anchor or ?query>]
```

| kind | points at | resolves through |
|---|---|---|
| `media` | a library item | `MediaView::url()` — full size, never a preset |
| `post` `page` `content` | a piece of content | `ContentService::publicPath()` |
| `category` | a category | `TaxonomyService::categoryPath()` |
| `tag` | a tag | `TaxonomyService::tagPath()` |

`post`, `page` and `content` are **one lookup**. Content ids are unique across both types and the
entity decides the shape of its own URL, so `post#5` naming a page still resolves to
`/about/team`. The prefix is a note to the next reader, not something that has to be right.

A trailing `#anchor` or `?query` is carried over: `post#42#installing` →
`https://example.com/post/hello#installing`.

**Recognised only in a link or an image destination.** `[x](post#42)`, `![x](media#1)`, and a
reference definition `[x]: media#1`. Never in running text and never in code, so a sentence
saying "see media#12" stays a sentence and this page can document the feature in itself.

A draft resolves. Linking to something not published yet is an ordinary thing to do while
writing, and the link starts working the moment it is published.

## 2. When the target is gone

The node is **unwrapped**, keeping whatever was inside it:

| written | target missing | renders as |
|---|---|---|
| `[the old post](post#42)` | | `the old post` |
| `![Screenshot](media#12)` | | `Screenshot` |
| `[an *old* post](post#42)` | | `an <em>old</em> post` |

One operation covers links and images alike, because an image's alt text *is* its child nodes.

The alternative — leaving `media#12` in the `src` — puts a broken image on a published page, and
it is the visitor rather than the editor who finds out. A purged file leaves behind the
description the person who inserted it wrote, which is the best thing available at that moment.

## 3. Where it happens, and what follows from that

**At render time, which is save time.** `ContentService::renderInto()` writes `lead_html` and
`body_html`, and `docs/performance.md` is emphatic that markdown is never parsed on a page view.
So this costs a page view exactly nothing — and the resolved URLs are *cached in those columns*
until something renders them again.

Two consequences, and both are handled:

**Moving the site.** Change `app.base_url`, then:

```bash
vendor/bin/dpress content:rerender
```

Not automatic, because nothing tells a site its own address changed. It is the one step to
remember in a move, and it is in the deployment notes for that reason.

**Renaming.** `ContentService::update()` notices when a slug or a `parent_id` moved, and
re-renders every document whose markdown mentions that id. A page moves more than itself — its
slug is a segment of every path beneath it — so its descendants count as moved too.

The candidate query is `markdown like '%post#42%'`, which also matches `post#421`. That is
deliberate: no amount of SQL is going to parse markdown, and re-rendering a document that did not
need it produces the same bytes. The loose end is the cheap one to leave.

## 4. How it reaches the renderer

`MarkdownRenderer` knows nothing about media, posts or categories, and that is worth keeping. So:

```
MarkdownRenderer::converter()
  builds an Environment
  emits markdown:environment          <- lazily, on the first render
    InternalLinks::onEnvironment()    <- resolved through the container at this moment
      adds a DocumentParsedEvent listener
        walks the AST, rewrites or unwraps
          LinkTargetResolverInterface <- the CMS lookups
```

The subscription is registered in `DpressServices::registerContentEvents()` as a Micro callable
rather than a closure, and that is the whole point: `EventService` runs it through the container
**when the event fires**. On a page view it never fires, so none of these objects are built.

Three reasons it works on the parsed document rather than on the rendered HTML:

1. A URL is a field of a node. On a string it is a regular expression over markup.
2. Unwrapping is `insertBefore` and `detach`. On a string it means matching a whole `<a>` element
   with whatever is nested inside it.
3. `html_input => strip` and `allow_unsafe_links => false` still apply afterwards, because
   rendering happens after the rewrite. A resolved URL is checked like any other.

### The one thing to be careful with

`LinkTargets` is **stateless on purpose**. An earlier version memoised its answers and was wrong
within a single request: renaming a post re-renders its referrers *in the same request as the
rename*, and those renders were handed the URL worked out before the slug changed — so the rename
appeared to do nothing at all. `LinkTargetsTest::testAnswersAreNeverKept` guards it.

The dedup that is safe — one picture twice in one document — lives in `InternalLinks`, which is
the only thing that knows where a document starts and ends.

## 5. In the editor

The attachments panel under the textarea has a **Reference** column showing `media#<id>`, so the
token can be read off the row and typed by hand. The insert button and the toolbar's 🖼 button
both write the reference rather than the URL.

Nothing else about attachments changed: attaching, hiding and detaching are still immediate
writes, and **the author owns the attachment list** — removing an image from the text does not
detach it, and detaching does not touch the text.

## 6. What was deliberately not done

**Existing documents were not converted.** Posts written before 0.19.0 hold absolute URLs and go
on working as ordinary links. A `content:tokenize` command that rewrote them was considered and
left out: it would be rewriting everybody's documents to fix a problem they may not have.

**Nothing is validated on save.** A reference to something that does not exist is saved happily
and degrades at render. Refusing it would mean an editor cannot write a link to the post they are
about to create next.
