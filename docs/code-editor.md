# The code editor's colours

**Status: built** (Dpress 0.94.0). How the admin's code fields - a post's markdown, the Additional
CSS boxes, a theme's templates, a Lua or a Pascal file - are coloured, and why it is done by **two
kinds of tokenizer**: grammars of our own for three languages, and EnlighterJS's rules for every
other. The site's *published* code blocks are another matter, see
[code-highlighting.md](code-highlighting.md).

## 1. How a field is coloured

The field is a `<textarea>`, always. `code-backdrop.js` puts a `<pre>` behind it holding the same
characters in colour, and turns the textarea's own text transparent over the top - so the value,
the selection, the undo stack and the form are the browser's, and nothing can write into the
field. With the scripts off it is a plain textarea.

The colours come from a **grammar**: a tokenizer answering `{start, end, type}` spans, in order and
never overlapping, and a class prefix. `data-code` on the textarea names the language, and
`grammarOf()` in `admin.js` finds the grammar:

| `data-code` | Tokenizer | Classes |
|---|---|---|
| `markdown` | `markdown-highlight.js` - ours | `md-*` |
| `css` | `css-highlight.js` - ours | `css-*` |
| `html` | `html-highlight.js` - ours | `html-*` |
| anything else EnlighterJS knows | `EnlighterJS.tokenize()`, through `Dpress.enlighterGrammar()` | `enl-*` |
| `text`, or a language nobody knows | none - a plain field | |

Everything else about the field - line numbers, wrap and its dotted line, full size, Tab, Enter
keeping the indent, PageUp/PageDown - is the same whichever tokenizer drew the colours.

## 2. Why three grammars of our own

**Each one knows something EnlighterJS does not**, and that was tested rather than assumed
(0.94.0): EnlighterJS was run over the same text and its tokens compared.

- **Markdown** - EnlighterJS colours a markdown line as one token. Ours knows **this site's**
  markdown: `media#12` (with its `#` set apart - the white `#`, which echoes the Dpress logo), a
  `{{ shortcode() }}`, a `---` that cuts a post into its lead and pages, `::: {.box}` fenced divs,
  `> [!WARNING]` callouts. Those are the marks worth confirming while typing, and the `---` rule
  is the renderer's transcribed, so the editor and the site agree about where a post breaks.
- **HTML with PHP in it** - EnlighterJS has an `xml` language and a `php` one, and a template is
  both at once. `php` reads the whole file as PHP (the HTML is only brackets, and
  `href="<?= $url ?>"` is one string); `xml` leaves attributes and the PHP uncoloured. Ours reads
  the PHP where it stands - between the tags and inside an attribute's value - and finds a block's
  end the way PHP's lexer does (`?>` in a string is text, in a `//` comment it ends the block).
- **CSS** - EnlighterJS's CSS is decent, and ours is still better at the one judgement a
  stylesheet needs, rule against declaration: it colours `@media` whole, `!important`, the `:` and
  `;`, and reads `.entry-content table, th > td` as one selector where EnlighterJS splits it into
  four pieces of one class.

**And CSS is kept for its weight above all.** The Additional CSS box is on every post and page
editor and on Settings > Theme. Coloured by EnlighterJS, every one of those screens would load its
62 KB bundle; `css-highlight.js` is a few KB and part of the admin already. Speed was measured and
is **not** the reason - ours is about twice as fast (2.7 ms against 5.7 ms over the 50 KB
`admin.css`), and both are well under a frame.

## 3. Why EnlighterJS for the rest

**Its rules are good where the language is a language and nothing more** - Lua, Pascal (the one
Dpress adds to it, 0.80.0), XML, JavaScript, JSON, INI, YAML, SQL, shell and some forty others -
and writing a tokenizer for each would be work that is already done. It is also the highlighter
the site's own code blocks use, so a language that is coloured on a page is coloured in the
editor.

- **`EnlighterJS.tokenize(code, language)`** is ours, added to the bundle by
  `assets/enlighter/build.js` next to the Pascal language: EnlighterJS 3.4 keeps its tokenizer
  inside its closure. It finds a language by any of its names (`html` is the XML language's alias,
  `js` JavaScript's), in any case, and answers `null` for one it does not have.
- **Positions come from the lengths of the token texts, not from a token's `index`.** The texts
  always add up to the code, but an `index` is sometimes counted from a substring a rule looked
  inside - in a JavaScript file with a docblock the indexes started again from 0 after token 71.
  `enlighter-tokenize.test.js` runs it over a real file to keep that true.
- **The bundle loads the first time a field needs it** (`withEnlighter()` in `admin.js`, the address
  from the layout's `data-enlighter-script`). A screen with only markdown and CSS never fetches
  it; a field waiting for it works as a plain one and is coloured when it arrives.
- **Its colours are ours**: an EnlighterJS token type is a letter and a number - `k0`..`k12`
  keywords, `s` strings, `c` comments, `n` numbers, `m` functions, `g` brackets, `x` markup - and
  `admin.css` colours them by the letter in the editor's palette (Dracula in the dark scheme, the
  same roles on white in the light one). So a Lua file looks like it belongs beside the markdown,
  rather than in whichever of EnlighterJS's themes the site chose for its pages.

## 4. When a language comes up

- **It is a language and nothing more** (Lua, Pascal, XML, JSON): EnlighterJS has it, or can be
  given it the way Pascal was (`assets/enlighter/pascal.js`, then `node assets/enlighter/build.js`).
  Nothing to do in the editor.
- **It is this site's own syntax, or two languages in one** (like the three above): a grammar of our
  own - a tokenizer and a prefix, `grammarOf()` naming it, colours in `admin.css`, a
  `*-highlight.test.js`. The CSS one is the smallest example to copy.
- **It is on a screen everybody opens**: weigh the 62 KB. A grammar of our own is the way to keep a
  field on every editing screen from loading the bundle.
