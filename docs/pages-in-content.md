# Long posts, in pages

A post or a page whose body has more than one `---` in it is served **one page at a time**, with
*Previous* and *Next* underneath.

```markdown
The lead. This is what listings show, and it is on page one only.

---

The first page of the body.

---

The second page.

---

The third.
```

**No new syntax.** The first `---` has always ended the lead; every one after it ends a page. The
same character doing a second job, and it reads the way it behaves — `---` has always meant "and
now something else". Nothing already written changes shape: a body with one separator, or none, is
one page and renders exactly as it did.

## What the reader gets

`?page=2`, `?page=3`, and so on. **Page one is the post's own address with nothing appended** — two
URLs for the same page is two of everything for a search engine and for anybody sharing a link.

A number that is not there is a **404**. `?page=7` of a three-page post names something that does
not exist, which is what the status code is for; clamping to the last page instead would let a
crawler index the same post at every number there is.

**The lead is on page one only.** It is the opening of the article, not a header to repeat above
every part of it. The title, the date and the categories are on every page, because they belong to
the post rather than to the part you happen to be reading.

## What the site stores

One column, as before. The renderer writes a marker between the pages of `body_html`:

```html
<p>The first page.</p><!--dpress-page--><p>The second.</p>
```

and `ContentPages::split()` cuts on it when a page is served. A body with no marker in it costs one
`str_contains` — the same guard the shortcodes and the highlighter use, so a site that never breaks
a post pays nothing for this on every page view.

**Each page is rendered on its own**, not one render cut up afterwards, because cutting HTML in
half is how a `<ul>` ends up with no closing tag. What that costs is the same thing the lead/body
split has always cost: a reference-style link defined on one page cannot be used on another.

One nice consequence falls out of it: the syntax highlighter loads on the page that has the code
block, and on no other, because the assets are decided from the finished HTML.

## A separator inside a code fence is not a separator

```` ```yaml ```` blocks containing `---` are extremely normal — a post explaining YAML front matter
is the obvious one — and splitting a document there would tear it in half at exactly the place its
author was writing about. So the scan skips fenced code, `` ``` `` and `~~~` both, and a fence is
closed only by its own character.

This fixed the lead/body split as well, which reads the same lines and had the same hole.

## After a rendering change

`body_html` is a cache, so posts written before this feature carry no markers until they are saved
again. `dpress content:rerender` rebuilds every one of them, and anything that was already
multi-page becomes multi-page.

## For a theme

`views/content/pages.phtml` is the navigation, rendered by the post and page templates, and it
renders nothing at all when there is only one page. A theme that overrides `content/single` gets
these variables:

| Variable | |
|---|---|
| `$body_html` | this page of the body, not all of it |
| `$page`, `$page_count` | where it is in the sequence |
| `$prev_url`, `$next_url` | `''` at each end |
| `$show_lead` | true on page one |
