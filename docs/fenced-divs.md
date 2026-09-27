# Boxes

A box around Markdown - `<div class="center">` around two pictures, a panel around a paragraph
and a list - written in the text itself:

```
::: {.center}
![Idle](media#55) ![Running](media#56)
:::
```

```html
<div class="center">
<p><img src="..." alt="Idle" /> <img src="..." alt="Running" /></p>
</div>
```

This is **Pandoc's fenced div** - djot and MyST have it too - so it is not a dpress invention,
and a document written for one of those reads the same here. Before it, raw HTML being stripped
from every document meant a box was something only a theme could make.

## Writing one

| | |
|---|---|
| `::: {.center}` | one class |
| `::: {#gallery .center .wide}` | an id and classes: `<div id="gallery" class="center wide">` |
| `::: center` | one bare class, the same as `{.center}` |
| `:::` | the end of the innermost open box |

- **Three colons or more.** The same rule as a code fence's three backticks, and three is what
  keeps it from ever meeting `std::vector`, `Foo::bar()` or IPv6's `::1` at the start of a line.
- **Anything inside is Markdown**: paragraphs, lists, pictures, a callout, a heading, a code block,
  another box. A `media#12` inside resolves like anywhere else.
- **A bare `:::` closes, and never opens.** A box with no name would be a `<div>` that does
  nothing - that is Pandoc's rule too.
- **Nesting**: a `:::` closes the innermost box. Using more colons on the outside is only for
  reading - they are not counted:

  ```
  :::: {.gallery}
  ::: {.row}
  ...
  :::
  ::::
  ```

- **Names are letters, digits, `-` and `_`**, and a box has one id. An attribute goes into the
  page, so nothing else is let in.
- **A `:::` inside a code block is code**, as a `#` in one is not a heading.

What a class *looks like* is the theme's business: a box only puts the class on the page. A theme
that wants authors to write `::: {.center}` gives `.center` a rule in its stylesheet.

## What a save refuses

The editor checks every box before it saves, and says which line is wrong:

- a `:::` that closes nothing - *Line 12 closes a box (:::) that was never opened.*
- a box that is never closed
- a box still open at a `---` page break - the lead and each page are rendered on their own, so a
  box cannot reach across one
- an attribute that is not `#an-id` or `.a-class`, or a second id
- an id used by two boxes

**The renderer itself is forgiving**: what is left open at the end of a part is closed there, and
a stray `:::` is text. So content saved before 0.82.0, or through a path that does not check,
still renders - the check is about the page somebody meant, not about a broken one.

## In the editor

The fences are coloured like a callout's marker, and the name of the box like a shortcode - so
where a box starts and ends reads at a glance, and a typo in its name stands out.

## Where it lives

`Content/FencedDivs.php` - the syntax, the stack of open boxes, and the check (`problems()`) -
and the four CommonMark pieces in `Content/FencedDiv/`: the node, the start parser, the parser
that decides which box a `:::` closes, and the renderer. Registered on
`MarkdownRenderer::EVENT_ENVIRONMENT` like `Callouts`.
