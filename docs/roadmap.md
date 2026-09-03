# What is planned, and what each one has to decide first

**Status: planned.** Written against 0.38.0, after a look at the blog that is moving here. None of
it is built. It is written down so tomorrow starts with decisions rather than with guessing.

Four things, and they are not equal: **the first one blocks importing posts** and the other three
are additions that can land in any order afterwards.

| | | Blocks what |
|---|---|---|
| 1 | Where a post lives — the URL | copying anything across |
| 2 | A recent posts block | nothing |
| 3 | Featured posts, by tag | nothing |
| 4 | A weight, for ordering posts by hand | nothing |

---

## 1. Where a post lives

The blog's posts are at the root with a trailing slash:
`https://gopherlab.net/internet-dosbox-x-windows-3-11/`. Here is what dpress does with that shape
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
- **Trailing slash**: accept both and 301 to one, or accept both and treat them as the same page.
  Accept-and-301 is consistent with what pages do now, and it is one rule rather than two URLs for
  one thing.
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
  the same `PageContext` [comments.md](comments.md) §3b asks for. Build it once, and both want it.

Half a day, most of which is the template.

---

## 3. Featured posts

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
| Post URL shape as a setting, `findByPath()` serving posts | §1 |
| `PageContext` — which content is being viewed | §2, and comments |
| `featured_posts` query, or block visibility rules | §3 |
| `weight` column, and `contentList` ordering by it | §4, §3 |
| A place before the content, if the featured strip becomes a block | §3 only |

---

## 6. To answer tomorrow

- **Post URLs**: `/<slug>` and keep every existing link, or `/post/<slug>` and redirect? (This one
  first — it blocks the import.)
- Does the blog use anything but post-name permalinks?
- **Featured**: the listing, or a block with visibility rules?
- Is the tag name `featured`, or a setting?
- **Weight**: a tiebreaker above the date, or the whole order?
