# What is planned, and what each one has to decide first

**Written against 0.38.0**, after a look at the blog that is moving here, so that the work would
start with decisions rather than with guessing. Three of the four are built since. §7, the
templates the 0.89.0 hierarchy leaves, was added later.

| | | Status |
|---|---|---|
| 1 | Where a post lives — the URL | **built in 0.47.0**, as the `post_path` setting |
| 2 | A recent posts block | planned |
| 3 | Featured posts, by tag | **built in 0.40.0**, as the listing rather than as a block |
| 4 | A weight, for ordering posts by hand | **built in 0.57.0** |

---

## 1. Where a post lives

> **Built in 0.47.0.** `post_path` is a setting: `/post/<slug>` (the default, and what every
> Dpress site had before) or `/<slug>`. The section below is what it was decided from, kept
> because the reasoning is the same one anybody weighing the two shapes has to do.
>
> It went exactly as sketched: `findByPath()` stopped rejecting a post, `publicPath()` answers
> with the shape the setting names, and `/post/<slug>` **301s** to wherever the post lives now,
> so the old address is a redirect and not a dead end. One thing the sketch did not mention -
> a link written *inside* a post was resolved when it was saved, so `dpress content:rerender`
> is part of the switch.

The blog's posts are at the root with a trailing slash:
`https://gopherlab.net/internet-dosbox-x-windows-3-11/`. Here is what Dpress does with that shape
today:

| URL | today |
|---|---|
| `/internet-dosbox-x-windows-3-11/` | **404** — posts do not live at the root |
| `/post/internet-dosbox-x-windows-3-11` | the post |
| `/post/internet-dosbox-x-windows-3-11/` | **404** — the post route rejects a trailing slash |
| `/about/` | the page — the catch-all already tolerates a trailing slash |

So as it stands, moving the blog changes every post URL. **That is the expensive part of a move**,
and it is expensive in a way comments are not: backlinks, search rankings, and every link anybody
ever wrote to a post. Decide it before importing, because changing it afterwards means changing it
twice.

### It is a small change, and the reason is already in the schema

`Content::$slug` is **globally unique across posts and pages** — one flat namespace, by design. So
a root-level URL has exactly one answer and there is nothing to disambiguate. `findByPath()`
already looks a slug up and then rejects anything that is not a page:

```php
$content = $this->findBySlug(end($segments), $publishedOnly);
if ($content === null || !$content->isPage()) {
    return [null, true];      // <- this is the whole restriction
}
```

The work is therefore: a setting for the post URL shape, `findByPath()` accepting a post when the
setting says so, `ContentService::path()` answering with the same shape, and the existing
**canonical 301** doing the rest — the machinery that already sends `/wrong/path/to/about` to
`/about` sends `/post/x` to `/x` for free.

### What to decide

- **Which shape**: `/post/<slug>` (today) or `/<slug>` (WordPress's, and the blog's). Only the
  second preserves the existing URLs.
- **Trailing slash**: **decided, 0.64.0** - one canonical URL without the slash, and a 301 to it.
  It lives in the app skeleton's `.htaccess` rather than in the router, so the redirect happens
  before PHP starts and costs no boot. What settled it was that the two shapes were already
  disagreeing: `/category/x/` was a 404, because `?` matches exactly one segment and the empty
  one after the slash makes three, while `/a-post/` answered **200** off the `/*` catch-all -
  the same page at two addresses with nothing naming the real one, which is the worse of the
  two because a 404 at least tells somebody. An nginx deployment gets nothing from this and
  would want the same rule in its own config, or a redirect in the middleware chain.
- **Old URLs that are not just this**: date-based permalinks, `?p=123`, an old feed address. If
  the blog only ever used post-name permalinks, the shape above covers everything and no redirect
  table is needed. Worth checking before assuming.

**The comments turned out not to be part of this.** The blog's Disqus threads are keyed
`573 https://gopherlab.net/?p=573` — the WordPress post id, not the permalink — so they follow the
id across and are indifferent to what the URL becomes. See [comments.md](comments.md) §6, step 2.
That leaves this decision resting on backlinks and search rankings alone, which is still reason
enough, but it is one argument rather than two.

---

## 2. A recent posts block

The easy one, and it needs nothing that does not exist: another type registered in `Blocks`,
alongside the tag cloud and the category list.

- **Settings**: how many (default 5), and whether to show the date.
- **The query is `content_list`**, already registered, already narrowable by a plugin — posts,
  published, newest first, limited.
- **"Not the one you are reading"** is the only interesting part: a recent-posts block in the
  sidebar of a post should not list that post. That needs to know which page it is on, which is
  the same `PageContext` [comments.md](comments.md) §3b asks for. **It exists since 0.62.0**, built
  for the comments, so this block reads it rather than building it.

Half a day, most of which is the template.

---

## 3. Featured posts

> **Built in 0.40.0**, as the listing recommended below. A setting, `featured_tag`, defaulting to
> `featured` — and empty means no strip at all. `HomeController` hands the template
> `$featured_posts`, at most five, and leaves them out of the list below through `exclude_ids`
> on `content_list`. No `featured_posts` query was needed: the strip is `content_by_tag` with a
> `max`, so since 0.57.0 the weight orders it too. The section below is what it was decided from.
>
> One thing the sketch did not mention: the tag is machinery and not a subject, so since 0.66.0
> it is **out of the tag cloud and the sitemap** — in a cloud it set the top of the scale by
> itself and squashed every real tag into the bottom buckets.

Five posts at the top of the front page, chosen by giving them a `featured` tag.

**The tag as the switch is a good choice**, because it needs no new column, no new screen and no
new concept: an author already knows how to tag a post, and un-featuring is removing a tag. Two
details to settle:

- **A convention or a setting?** `featured` hardcoded is simpler; a setting naming the tag means a
  Hungarian site can call it `kiemelt`. A setting, defaulting to `featured`, costs almost nothing.
- **Do featured posts also appear in the list below?** Pinned *and* repeated four rows down reads
  as a bug. Excluding them is one more condition on the listing query.

### The part worth knowing before starting

**This is the feature that makes block visibility rules necessary**, and that is worth saying
plainly because it was deliberately left out of 0.37.0.

A featured strip belongs at the top of the *front page* and nowhere else. As things stand a block
renders in a place on every page that renders that place, so a "featured posts" block would sit on
top of every post as well. Two ways out:

- **Do it in the listing**, not as a block: a `featured_posts` query, `HomeController` passing them
  to the template, and the theme deciding what a featured post looks like. No new core concept, and
  it is genuinely home-page furniture rather than a block somebody moves around.
- **Or add visibility rules** to blocks — "front page only", "posts only" — and make it a block.
  That is a second grammar to design, and it is the thing that was put off; if it is going to be
  built, this is the feature that should pay for it.

**Recommended: the listing.** A featured strip is not something anybody will want in a sidebar,
and the block version costs a new grammar to express one condition.

---

## 4. A weight, for ordering by hand

> **Built in 0.57.0**, as the tiebreaker recommended below. An `int weight` on `Content`,
> `weight desc` in front of the date in `contentList`, `contentByTag` and `contentByCategory`
> — through one `orderContent()` helper, so the three cannot drift apart and a post cannot
> float on the front page while sitting still in its category — and in front of the title in
> `contentChildren`. A box in the
> editor and a sortable column in the list. The section below is what it was decided from.
>
> **0.75.0 put up and down beside it.** An `int position` under the weight — `weight desc,
> position asc`, then the date or the title — set only by the admin list's two buttons, within
> one weight: the weight still pins, the position arranges. A move renumbers its group from 1
> and gives a revision to the moved post alone; the rest are renumbered with a plain update.
>
> Three things the sketch did not mention. The number is **signed**: "push this one down" is
> as real a wish as pushing one up, and `-1` says it without renumbering everything else.
> It is **validated rather than cast**, because `(int)` never fails — `1o` would have been 1
> and `x` would have been 0, with the screen reporting that it saved. And it orders the
> **children of a page** as well, so "In this section" and the page tree in the admin can be
> put in an order somebody chose rather than the one the alphabet chose.

Posts have no drag handle, because they are not a tree — so ordering them by hand needs a number
in the editor rather than a position among siblings. That is the right read: `position` belongs to
things that have siblings, and a post's siblings are "every other post".

What has to be decided is what the number *means*, and there are only two honest answers:

- **A tiebreaker on top of the date**: `order by weight desc, published_at desc`. A weight of `0`
  is normal, anything higher floats up. **A no-op until somebody sets one**, which is what makes it
  safe to add to every listing at once.
- **A sort order in its own right**, replacing the date. Simpler to reason about and much worse to
  live with: every new post arrives at weight 0 and lands at the bottom.

**Recommended: the tiebreaker**, `weight desc, published_at desc`, applied in `contentList` so that
the front page, categories and tags all agree about what order posts are in.

The rest is mechanical: an `int weight` column on `Content` defaulting to 0, a number field in the
editor, and the column in the admin list so it can be seen and sorted. Adding a column before 1.0
means `database/reset.sh` — which is safe again now that the fox is a seed file.

**It overlaps §3 more than it looks.** "Featured, and in this order" is a weight on a featured
post; "pin this one post to the top" is a weight with no tag at all. If both get built, build the
weight first and let the featured strip order by it.

---

## 5. What core has to grow

Everything above, as one list — three of the five are wanted by more than one feature, which is the
argument for doing them first.

| Core change | Wanted by |
|---|---|
| Post URL shape as a setting, `findByPath()` serving posts — **built in 0.47.0** | §1 |
| `PageContext` — which content is being viewed — **built in 0.62.0** | §2, and comments |
| `featured_posts` query, or block visibility rules — **built in 0.40.0** as `content_by_tag` with a `max`, and `exclude_ids` on `content_list` | §3 |
| `weight` column, and `contentList` ordering by it — **built in 0.57.0** | §4, §3 |
| A place before the content, if the featured strip becomes a block — not needed, it is the listing | §3 only |

---

## 6. To answer tomorrow

- ~~**Post URLs**~~ — answered: both, as a setting, and `/post/<slug>` redirects to whichever is
  in force. gopherlab is on `/<slug>`.
- Does the blog use anything but post-name permalinks? **Still worth checking** - date-based
  permalinks or `?p=123` would need a redirect table, which none of this provides.
- ~~**Featured**~~ — answered: the listing.
- ~~Is the tag name `featured`, or a setting?~~ — a setting, `featured_tag`, defaulting to
  `featured`.
- ~~**Weight**~~ — answered: a tiebreaker above the date.

---

## 7. Templates, after the hierarchy

> **Written against 0.89.0**, which added WordPress's template hierarchy: `category-<slug>`,
> `category-<id>`, `category`; the same for tags; `page-<slug>`/`page-<id>`; `single-<slug>`/
> `single-<id>`; `home` - see [themes.md](themes.md) §4. It came out of wanting a **gallery for a
> category** and **a page that links the categories with their pictures**, and these three are
> what that leaves. None is built.

| | | Status |
|---|---|---|
| 7.1 | A page's template chosen in the editor | **built in 0.91.0** |
| 7.2 | A post's template by its category | planned |
| 7.3 | The error pages from the theme | planned |

### 7.1 A page's template chosen in the editor

> **Built in 0.91.0**, as below: a `content.template` column (so revisions keep it, and the first
> migration since the schema was squashed adds it), the chosen template before `page-<slug>`,
> and a template the theme no longer has kept on the select, marked, and falling back to `page`.

WordPress's *Template* dropdown. The hierarchy ties a layout to one page by its slug or its id,
which is right for a theme made for one site and wrong for anything an author should decide: the
"Categories" page and a second gallery page want the same layout without a file each. So the page
form gets a **Template** select, filled from the theme's `dpress/content/page-template-*.phtml`
(`page-template-gallery.phtml` shows as *Gallery*), and the page stores the name.

**What to decide:** where it is stored - a column on `content` (a migration, and the revision
history keeps it) or in the page's front matter; which wins when a page has both a chosen template
and a `page-<slug>.phtml` (the chosen one, most likely: it is the explicit choice); and what a
page whose template the new theme does not have falls back to (`page`, quietly, with the editor
saying so). This is the one the category-pictures page needs most.

### 7.2 A post's template by its category

`single-category-<slug>.phtml`: every post in a gallery category gets the picture-first layout
without a file per post. WordPress does not have it; it fits the gallery. **What to decide:** a
post in two categories that both have one - the first by the category's position, probably - and
whether it goes before or after `single-<slug>` (after: one post's own template is more
particular than its category's).

### 7.3 The error pages from the theme

A 404 drawn by the site's own layout rather than a plain page. **Checked: a theme cannot today.**
`WebApp::sendError()` (dynart-micro) prints a static `<code>.html` from the folder the
`app.error_pages_folder` config names, or a bare placeholder - no layout, no menu, no way back
into the site. The hierarchy's answer is `dpress/error-404.phtml`, then `dpress/error.phtml`, drawn
with the layout like any page, then the static file as it is now. **What to decide:** where the
hook is - Dpress overriding `sendError()` in `DpressWebApp` keeps micro as it is - and that an
error inside the error template falls back to the static page rather than to a second error.
