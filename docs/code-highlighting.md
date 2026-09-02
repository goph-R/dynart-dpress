# Syntax highlighting

**Status: built** (dpress 0.34.0).

````
```php
echo "hello";
```
````

The word after the backticks names the language. Thirteen themes ship; one is chosen in
**Settings → Code theme**, and *No highlighting* is one of the choices.

---

## 1. The colours are not in the document

This is the decision everything else follows from.

A fenced block is stored as:

```html
<pre class="language-php" data-enlighter-language="php">echo &quot;hello&quot;;</pre>
```

**No `<code>` inside a highlighted block**, which is not a stylistic choice. EnlighterJS reads the
`innerHTML` of the element it matched and unescapes it, so a `<code>` wrapper is not ignored — it
is *displayed as the first line of the code*, tag and all. Enlighter's documented markup is a
`<pre>` with the code directly inside, and replacing the whole `<pre>` also keeps the theme's own
`article pre` styling off a highlighted block. A fence with **no** language keeps `<pre><code>`,
exactly as before.

and [EnlighterJS](https://enlighterjs.org) colours it in the browser.

A server-side highlighter would write a `<span>` per token into `body_html` — **markup about how a
thing looks, living inside the content**. That is the mistake `media#12` exists to avoid, one
level down: a document should say what it *is*, not how it renders today. It would also mean
`dpress content:rerender` over every post to change a theme, and again to upgrade the highlighter.

As it is: switching theme changes every page at once, upgrading the highlighter changes every page
at once, and no stored document is touched by either.

`class="language-php"` is on the `<pre>`, as the author wrote it. Nothing of ours reads it —
EnlighterJS reads the data attribute — but a document that leaves this CMS should still say what
its code is written in.

## 2. What it costs, and what it does not

**A page with no code block loads nothing.** The front end of dpress ships no JavaScript at all,
and that stays true for every page that has no code on it. The test is one `str_contains` over
HTML already in memory — the same guard `Shortcodes::expand()` uses.

A page *with* code loads 58 KB of script and about 14 KB of stylesheet, both cached after the
first, both served from this site. Not from a CDN: a visitor reading somebody's code sample should
not be announced to a third party for it, which is the same reason the `video` shortcode embeds
from `youtube-nocookie.com`.

Without JavaScript the code renders as a plain, correctly escaped block — exactly what it was
before this feature existed.

## 3. Language names

The first word after the backticks. Anything not in the alias table is passed through as written,
so a language EnlighterJS learns later works without a change here.

| written | used |
|---|---|
| `c++` | `cpp` |
| `c#`, `cs` | `csharp` |
| `js` | `javascript` |
| `ts` | `typescript` |
| `py` | `python` |
| `rb` | `ruby` |
| `sh`, `bash`, `zsh` | `shell` |
| `yml` | `yaml` |
| `html`, `htm` | `xml` |
| `md` | `markdown` |
| `golang` | `go` |
| `text`, `txt`, `plaintext` | `raw` |

`c`, `cpp`, `csharp`, `java`, `python`, `php`, `go`, `rust`, `sql` and the rest need no alias.

**An unknown language is passed through, not refused.** ` ```pseudocode ` is somebody's own
convention and renders as an ordinary block.

**A fence with no language is byte-identical to what it was**: `<pre><code>`.

## 4. Themes

`enlighter`, `atomic`, `beyond`, `bootstrap4`, `classic`, `dracula`, `droide`, `eclipse`,
`godzilla`, `minimal`, `monokai`, `mowtwo`, `rowhammer` — and `none`.

**Off is the word `none`, not an empty value.** `SettingService::get()` reads `''` as *absent* and
answers with the default, so an empty setting would mean "the default theme" rather than "no
highlighting". A name that is not one of the thirteen is treated as off rather than guessed at:
the setting is writable by hand and by a plugin, and a stylesheet path is not something to build
out of whatever is in a row.

**The block gets its own padding.** Every theme paints the background on `.enlighter-default` and
sets `padding: 0` there, so the first line of code sits against the top edge of the colour. One
rule corrects it, emitted straight after the theme's stylesheet — both selectors are a single
class, so source order decides, and the theme link is added to the page after the layout's own
`<style>`. It lives in `CodeAssets::PADDING` rather than in a layout so that a theme author does
not have to know it is needed, and so the vendored file stays unmodified.

Each theme file is self-contained — layout and colours, about 14 KB — so a page loads one
stylesheet and no base underneath it.

## 5. Upgrading

**One `dpress content:rerender`**, once, to give existing posts the attribute the highlighter
reads. Nothing after that: a theme change and a highlighter upgrade both change every page without
touching a document.

## 6. What was deliberately left out

- **Line numbers and the copy button.** EnlighterJS can do both; neither was asked for, and both
  are one option in `CodeAssets::tags()` when they are.
- **A light and a dark theme switched by `prefers-color-scheme`.** Enlighter's themes are each
  individually light or dark rather than designed to be stacked, so pairing them means scoping two
  stylesheets and checking they do not leak. One theme, chosen, until that is worth doing.
- **Highlighting in the admin editor.** The markdown field is a textarea with a toolbar,
  deliberately not an editor — see `docs/media-in-the-editor.md` §1.

## 7. Licence

EnlighterJS 3.4.0 is MPL-2.0, vendored unmodified in `assets/enlighter/`.
