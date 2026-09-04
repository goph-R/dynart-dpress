# Shortcodes

**Status: built** (dpress 0.32.0, embeds in 0.33.0).

```
{{ video('media#10') }}
```

A registered name, optional arguments, and whatever HTML the handler returns. Two ship - `video`
and `icon`; everything else comes from a plugin.

---

## 1. The grammar, and why it is this small

```
{{ name }}                          no arguments
{{ video('media#10') }}             positional
{{ gallery(limit=6, size='l') }}    named
```

Values are quoted strings, integers, floats, `true`, `false` and `null`. **A bare word is not a
value** — `size=large` is refused, `size='large'` is not — because guessing where an unquoted
string ends is where a small grammar stops being small.

There is no nesting and no expression. The moment an argument can contain an argument this is a
language with an evaluator in it, and an evaluator running over text an author typed is a
different thing to be responsible for than a lookup table.

Positional arguments arrive under `0`, `1`, …, named ones under their name, so a handler reads
`$args[0] ?? ''` and never has to know which the author wrote.

## 2. A shortcode can be written about

```
Write `{{ video('media#10') }}` to embed one.
```

renders those characters, and so does a fenced block. That is not a special case — it falls out of
`{{` being claimed by a **CommonMark inline parser** rather than by a regular expression over the
markdown. CommonMark claims a code span before any inline parser is offered the text.

This is the one place dpress deliberately differs from WordPress in *mechanism* rather than in
taste. A regex cannot tell a shortcode from a shortcode being quoted, and a CMS whose own
documentation cannot be written in it has a bad idea in it.

`\{\{` escapes too, and always did: `{` is ASCII punctuation, so CommonMark's backslash escapes
cover it without anything of ours.

## 3. When it runs — **on the page, not on save**

This is the important one, and it is where dpress gives something up.

Everything else about a document is resolved once, at save: the markdown becomes `body_html`,
`media#10` becomes a URL, and a page view parses nothing. Shortcodes break that rule on purpose.
A gallery's contents change without anybody touching the posts that embed it, and working out
which posts to re-render is the referrer-chasing that `ContentService::rerenderReferrers()`
already shows the shape of — for a rename it is worth it, for a gallery it is a losing game.

So what is written into `body_html` is a **marker** carrying the call:

```html
<!--dpress-sc eyJuIjoidmlkZW8iLCJhIjpbIm1lZGlhIzEwIl19-->
```

and `Shortcodes::expand()` swaps markers for output in `AbstractController::render()`, over the
finished page.

**What it costs**, measured on the development site:

| | |
|---|---|
| a page with no shortcode | 35.3 ms — one `str_contains`, inside the noise |
| a page with one `video` | 36.4 ms |

The markdown is still rendered once. What moved to the page is the shortcodes themselves, and a
site that uses none pays for a string search over a string that was already in memory.

Two things follow. A revision in the history shows the marker, not the output, which is right:
history should show what the author wrote. And `content:rerender` is **not** needed when a
shortcode's output changes — that was the whole point.

## 4. The marker cannot be forged

`html_input => 'strip'` means raw HTML never survives from a document into `body_html`, so nothing
an author types can become a marker. Inside a code span it renders escaped. The only thing that
writes one is the parser.

The payload is base64 for one blunt reason: an argument containing `-->` would end the comment
early and spill the rest of the post onto the page.

## 5. Writing one

```php
class GalleryShortcode {
    public function render(array $arguments): string {
        $id = (int)($arguments[0] ?? 0);
        return '<div class="gallery">…</div>';
    }
}
```

and in a plugin:

```php
public function shortcodes(): array {
    return ['gallery' => [[GalleryShortcode::class, 'render'], Shortcodes::BLOCK]];
}
```

A **Micro callable**, so nothing is constructed until a page containing one is rendered — the same
laziness the event subscriptions use.

**`BLOCK` or `INLINE`** decides whether it may live inside a paragraph. A block shortcode alone in
a paragraph replaces the paragraph, because a `<figure>` inside a `<p>` is invalid and a browser
rearranges it rather than ignoring it. A block shortcode *among words* is rendered where it
stands: that is an author asking for something that cannot be done, and tearing their paragraph in
half is a worse answer.

**A handler escapes its arguments.** The renderer strips raw HTML from documents on purpose; a
shortcode is a deliberate hole in that, and the arguments came from an author.

## 6. When something is missing

| what | what happens |
|---|---|
| the name is not registered when the post is **saved** | the text is left exactly as typed, so the document can be written before the plugin is installed |
| the name is gone by the time the page is **rendered** | an HTML comment naming it, and a logged warning |
| the arguments are malformed | left as text |
| `video` cannot do what it was asked | a comment saying why |

The second row is the plugin that got switched off this morning. A post that mentions its
shortcode is a page with one thing missing, not broken content — the same answer `FormWidgets`
gives for a field type nobody registered.

## 7. `video`

```
{{ video('media#10') }}
```

One shortcode for every kind of video, dispatching on what it was handed:

| given | rendered |
|---|---|
| `media#10` | a `<video>` with the file's URL and its alt text as the label |
| a link to a video file (`.mp4`, `.webm`, `.ogv`, `.mov`, `.m4v`) | the same |
| a YouTube link — `watch?v=`, `youtu.be/`, `/embed/`, `m.youtube.com` | the player, from **`youtube-nocookie.com`** |
| a Vimeo link — `vimeo.com/<id>` or `player.vimeo.com/video/<id>` | the player |
| anything else | a comment saying so |

`?t=90` on a share link survives as the player's `start`, because a timestamp is the one thing
somebody took care over.

**The no-cookie domain** serves the same player and stores nothing until somebody presses play.
Both work identically, so the quieter one is what goes on other people's sites.

**The host is matched at its end, not searched for.** `notyoutube.com` and
`youtube.com.example.net` are not YouTube, and a `str_contains` would embed both.

It refuses politely: `media#2` naming an image says so, and a file that has been deleted says
that instead of rendering a player that plays nothing.

## 8. `icon`

```
{{ icon('star') }}                        <i class="fa-solid fa-star" aria-hidden="true"></i>
{{ icon('rust', style='brands') }}        <i class="fa-brands fa-rust" ...>
{{ icon('star', label='Favourite') }}     <i class="fa-solid fa-star" role="img" aria-label="Favourite">
```

**The CMS ships no icons.** This writes the classes and nothing else, so whether anything appears
is a question about the theme's stylesheet - Font Awesome's class names are the vocabulary because
they are the ones an author looks up. An icon set is a design decision with a licence attached, and
a CMS bundling one makes that decision for every site that ever uses it.

It exists because **raw HTML in a post is stripped** (`html_input => 'strip'`), so
`<i class="fa-solid fa-star">` renders as a gap. That strip is what stops a compromised account
putting a script on every page; the shortcode is the way through it, and it is a lookup table
rather than a hole - the name is matched against `[a-z0-9-]+` and the style against a list, because
a post must not be able to name a class somebody else's stylesheet defines.

**Silent unless it is given a label.** An icon beside a word that already says the same thing is
decoration, and a screen reader announcing "star star" is worse than one that says it once. A
`label` is the author saying this one carries the meaning on its own.

### 8.1 Installing an icon set in a theme

The theme asset folder is **flat** - the route serves `name.ext` and no directories - so Font
Awesome needs its `url()`s rewritten from `../webfonts/fa-solid-900.woff2` to the bare file name.
`woff2` is on the allowlist. Then, in the theme's head:

```php
<?php if ($theme->exists('fontawesome.css')): ?>
    <link rel="stylesheet" href="<?= esc_attr($theme->url('fontawesome.css')) ?>">
<?php endif ?>
```

`exists()` rather than an unconditional `<link>`, so a theme works with or without one instead of
asking every site that never installed one for a 404 on every page.

## 9. What was deliberately left out

- **Theme shortcodes.** A theme is data and templates; a shortcode is code. A theme that wants one
  ships a companion plugin.
- **Nesting**, and shortcodes in arguments. See §1.
- **Caching an expansion.** There is no page cache to put it in yet, and a shortcode that is
  expensive enough to need one is a shortcode that should be doing less.
