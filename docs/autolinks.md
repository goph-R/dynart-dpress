# Bare URLs

**Status: built** (dpress 0.48.0).

A URL written in a sentence becomes a link:

```markdown
The result is on https://example.com/page, have a look.
```

```html
<p>The result is on <a href="https://example.com/page">https://example.com/page</a>, have a look.</p>
```

Somebody writing that meant a link. Making them write it twice — `[https://…](https://…)` — is a
tax on the common case, so it is **on unless a site turns it off**, which is the opposite of how a
new setting usually arrives.

---

## 1. Never inside code

A post explaining how to do something is full of URLs that are examples rather than destinations,
and neither of these is touched:

````markdown
```
curl https://example.com
```

An inline `curl https://example.com` as well.
````

That holds because of *where* the feature is plugged in rather than because of a check it performs.
It is an **inline parser**, and inline markup does not run inside a fenced block, an indented block
or a code span — the same reason `**bold**` stays four asterisks in there. It has a test of its own
precisely because nothing in the class would fail if that stopped being true.

A URL that is already a link is left alone too: `[the site](https://example.com)` produces one
`<a>`, not one wrapped in another.

## 2. What it deliberately does not do

CommonMark's own autolinker does three things rather than one. This does the first:

| written | linked | why |
|---|---|---|
| `https://example.com` | **yes** | |
| `http://example.com` | **yes** | |
| `www.example.com` | no | a host with no scheme is a guess about what was meant |
| `nobody@example.com` | no | an address written as a fact about somebody becoming a clickable `mailto:` is a decision the author did not make |
| `ftp://example.com` | no | |

`HttpAutolinkParser` is the library's parser with a narrower idea of where it may start. Both of the
others are a small change if a site wants them; neither is a default.

## 3. The setting

*Settings → Bare URLs*, or `dpress setting:set -name autolink -value 0`.

**It is applied when a document is rendered**, and the HTML was written when the post was saved — so
changing it does nothing to what is already stored until:

```
dpress content:rerender
```

The same rule as the post URL shape, and for the same reason: dpress renders markdown once, at save
time, and a page view is a read of the result.

## 4. Where it lives

`Autolinks` subscribes to `MarkdownRenderer::EVENT_ENVIRONMENT` as a Micro callable, so neither it
nor the settings behind it is built until something actually renders markdown — which on a page view
is never. The renderer itself learns nothing: it still knows nothing about settings, posts or media,
and a subscriber that decides what the environment gets is the same shape `InternalLinks`,
`Callouts` and the shortcodes already use.
