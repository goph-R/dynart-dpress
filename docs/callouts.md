# Callouts

A blockquote with a marker on its first line is a coloured panel.

```markdown
> [!WARNING]
> Do not run `media:purge` on a site you have not backed up.
```

renders as a panel with an orange rule down its left side, a tenth of that orange behind it, and a
warning icon in the corner. Without the marker it is a grey quote with a quote mark, which is what
every blockquote in a document now looks like.

## The markers

| Marker | Looks like |
|---|---|
| `[!NOTE]` `[!TIP]` `[!IMPORTANT]` `[!INFO]` | info — blue |
| `[!WARNING]` | warning — amber |
| `[!CAUTION]` `[!DANGER]` | danger — red |
| *none* | quote — grey |

Case does not matter. `NOTE`, `TIP`, `IMPORTANT`, `WARNING` and `CAUTION` are GitHub's five, so a
README pasted into a post arrives styled; `INFO` and `DANGER` are here because they are what people
guess.

An unrecognised marker is somebody's own text, not a broken panel — `> [!TODO]` stays a plain quote
with `[!TODO]` visible in it. And the marker is only a marker at the very start of the quote, so

```markdown
> See [!WARNING] in the docs.
```

is a sentence.

## Why a blockquote

**The syntax is valid CommonMark either way**, and that is the whole reason for it. Anywhere without
dpress — a README on a git host, an editor preview, a document exported from here — it is still a
blockquote, still readable, with a visible `[!WARNING]` where the styling would have been. A
convention that only works inside one CMS breaks the moment a document leaves it, and markdown that
cannot leave is not really markdown.

It also means **the content is markdown**:

```markdown
> [!NOTE]
> Bold, [links](post#12), lists and `code` all work in here.
>
> So do further paragraphs.
```

because CommonMark parsed all of it before any of this ran. A shortcode could not do that —
`{{ warning('…') }}` takes a string, and a panel holds prose. This is the same reason
[internal links](internal-links.md) are written as markdown links rather than as a shortcode.

## What is stored

A class:

```html
<blockquote class="callout callout-warning"><p>Do not…</p></blockquote>
```

The colour and the icon are in the theme's stylesheet, where presentation belongs — the icons are
data-URI SVGs on a `::after`, for the reason [syntax highlighting](code-highlighting.md) keeps its
colours out of `body_html`. Restyling every panel on a site is an edit to one file, not
`dpress content:rerender` over every post that has one.

## Restyling

Four custom properties, one per kind. The border is the colour at full strength and the background
is the same colour at a tenth of it through `color-mix`, so a panel is always in key with itself and
overriding one value moves both:

```css
:root {
    --quote:   #71717a;
    --info:    #2563eb;
    --warning: #d97706;
    --danger:  #dc2626;
}
```

A theme that wants a fifth kind needs a stylesheet rule and nothing else in PHP — but the marker
that produces it has to be in `Callouts::KINDS`, which is a constant. Making that registry
extensible is a small change and is worth doing the first time a plugin asks for it.
