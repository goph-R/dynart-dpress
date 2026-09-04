# Changelog

All notable changes to **dpress** are documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/).

---

## [0.46.1] &ndash; 2026-09-04

### Fixed
- **Saving a post did nothing and brought the editor back.** 0.46.0 put the hidden `cursor_line` field in the *required* group of the content form - `addFields()` makes a field required unless it is told otherwise - so an empty one failed validation on every save. Being hidden it had nowhere to print the error, so the screen simply reloaded with no explanation. It is in the optional group now, and the test that guards this asserts the **whole set** of required fields rather than a sample of it, so the next field put in the wrong group fails a test instead of a save.

## [0.46.0] &ndash; 2026-09-04

### Added
- **A preview opens on the page the cursor was on.** On a body written in `---` parts, previewing from the middle of part four used to open at part one and leave you paging back to what you were looking at. The Preview button now sends the line the cursor was on and the redirect goes straight to that page.
- `MarkdownRenderer::pageOfLine()`, which answers by walking the document the way `pages()` walks it - so a `---` inside fenced code is not a break here either, and the `---` after `---` that `pages()` drops as a typo is dropped here too rather than putting every later page out by one.
- `Dpress.lineOfCursor()` in `admin.js`.

### Notes
A **line** goes over the wire and not `selectionStart`: that counts UTF-16 units while PHP counts bytes, and the two agree until the first accented letter - on a Hungarian post the preview would have opened confidently on the wrong page. A line number means the same thing in both languages.

With the script off there is no line, and a preview opens at page one exactly as it did before.

## [0.45.0] &ndash; 2026-09-04

### Changed
- **A preview pages through a body written in `---` parts**, the way the published post does. It used to show the whole body in one go, because the page links would have been GETs of a route that only answered POST. So the preview is **post, redirect, get** now: the boxes arrive by POST, which is the only way to send them, and every page after that is a GET of a real address. The pager is ordinary links again and a theme needs to know nothing about previews. Refreshing the tab stops re-posting into the bargain.
- `pagedBody()` and `pageUrl()` take query parameters every page of a route has to keep.

### Notes
The redirect needs the boxes to outlive the POST, so they go in the **session** - not the database. The post itself is still never written, which was and is the whole point; this is one tab's copy of what somebody typed, it belongs to one person, and it goes when the session does. Three are kept, so previewing in two tabs works and a long session is not a place a hundred drafts pile up. A token that has fallen off the end says so and offers the way back to the editor, rather than quietly rendering the saved post under a bar claiming it is unsaved.

## [0.44.1] &ndash; 2026-09-04

### Changed
- **A tree table now sits in a box of its own, like a dynamic list.** The two are the same furniture and read as two different things: a list ran edge to edge in its panel while the menu items, the categories and the blocks were inset by the panel's 22px. The `tree` partial brings its own `.tree-list` box the way a list does, so the three screens stopped wrapping it in a `.panel`, and rows gained the hover a list has. On the blocks screen each place is named above its box rather than inside it, so every table on the page starts at the same edge.

## [0.44.0] &ndash; 2026-09-04

### Added
- **Preview.** A button beside *View* in the editor that opens what is in the boxes right now, rendered through the theme, **saving none of it**. It is a submit button carrying `formaction` and `formtarget`, so the values travel exactly as Save would send them - no second copy of the fields and no JavaScript - and the editor page is left as it was.
- A `preview-bar` partial, drawn at the top of a preview so a page of unsaved words is never mistaken for the live one. Themes that replace `content/single.phtml` want one line for it, see `docs/themes.md` §7.

### Changed
- **The View button now shows for a draft**, not only for a published post. The front end has always served an unpublished post to anybody holding `post.update` - `maySeeDrafts()` has been there all along - so hiding the button was the only thing keeping a draft out of sight. A never-saved auto-draft still has no button, because it holds nothing; that is what Preview is for.

### Notes
Saving first and looking after would have been a tenth of the work and is wrong for the case that matters: on a **published** post it would put the unsaved edits live, which is the opposite of a preview. It would also write a revision every time somebody peeked. So the preview builds a `Content` that lives for one request and never reaches the entity manager.

**The preview route checks the permission and not a CSRF token**, deliberately. `Form::process()` mints a fresh token into the session every time it runs, so checking one here would spend the token printed on the editor page still open behind the new tab, and the next Save would be refused as a forgery. It is safe to leave out only because this route writes nothing and the markdown renderer strips HTML: there is no state to change and no script to reflect.

## [0.43.0] &ndash; 2026-09-04

### Added
- **A published date you can set.** A *Published* box in the editor, beside Status and behind the same permission, holding a plain `1999-01-02` - or `1999-01-02 14:30` when the time matters, which for two posts on the same day it does. **This is what a migration needs**: a post written in 2014 is published today and dated then, and the archive, the ordering and the byline all read that one column.
- `-date` on `content:create` (with `-publish`) and on `content:publish`, so a scripted import can carry the dates across.
- `Dates::parse()` and `Dates::input()` - a typed date to stored UTC and back, in the site's timezone, since that is the clock the person typing is looking at.
- `ContentService::setPublishedAt()` and the `content:rescheduled` event.

### Notes
A text box rather than a date picker: writing a date is faster than four clicks. Which means a typo is possible, so a date that cannot be read **stops the save** and comes back as a sentence about that box, with what was typed still in it. `strtotime()` is deliberately not used - it has an opinion about which half of `02/01/1999` is the month and reads trailing rubbish as a modifier, so a typo would have become a date somewhere near the one that was meant, silently.

The date is a publishing decision rather than an edit, which is why it sits with `publish()` rather than being another field `update()` writes: the public queries ask for `published_at <= now`, so dating a post forward hides it. `content:rescheduled` is a separate event from `content:published` for the same reason a re-date is not a publication - a listener that mails or pings a feed must not do it all again for a corrected date.

## [0.42.0] &ndash; 2026-09-04

### Changed
- **"Points at" now decides what the rest of the menu item editor offers.** Choose *A category* and Target lists the categories; choose *A tag* and it lists the tags. Before, one select held every post, page, category and tag at once whatever kind was chosen - and *Address* sat there under all five kinds, which is how filling it in while Points at still said *A post or page* became the obvious way to add an external link and saved a post link with no post. A kind that points at nothing in this site hides Target; only an external address offers Address. Nothing is cleared by looking elsewhere - the fields are hidden, not emptied, so a kind chosen by mistake and put back finds what was typed.
- **A target option says which kind it is**: `content:12`, `category:3`, `tag:7`, in the same words `target_type` already used. The old `12` / `c12` / `t12` encoding needed a rule of its own to read, and it is the rule that got `ltrim($value, 'ct')` wrong in 0.41.0. Form values only; nothing stored changes.

### Added
- `MenuItem::targetValue()` and `MenuItem::targetIdIn()` - the entity that owns both columns now owns the encoding between them, rather than the form writing one half and the controller reading the other.
- `Dpress.targetOptionsFor()` and `Dpress.targetFieldsFor()` in `admin.js`, and the `initTargetFields()` binder behind them.

### Notes
With the script off, all three fields stay visible and the whole list is offered - the admin exactly as it was. **The server still decides either way**: a value whose kind is not the kind chosen is no target at all, and `itemProblem()` refuses the combination with the reason on the form.

## [0.41.1] &ndash; 2026-09-04

### Fixed
- **Deleting a menu item deleted nothing.** It printed `{"csrf": "..."}` into the browser and moved the item to the top of the menu instead. The `#[Route]` for the delete had been written above the *next* method's docblock, and an attribute binds to the declaration that follows it - so `POST /admin/menus/items/delete/<id>` was reaching `moveItem()`, which answers with data rather than a redirect because a drag has already moved the row on screen. Every check there was passed: the route existed, it was a POST, it was not a bulk delete. Nothing asked which method it landed on. Two tests do now, one per route and one on the shape that caused it.
- **A new category's slug came out `item`, then `item-2`, `item-3`.** The editor posts the slug field whether or not anybody typed in it, so an untouched field arrives as the empty string - and `$data['slug'] ?? $name` only reaches the name when the key is *missing*. The name was unreachable. An empty field is a slug nobody chose, not a slug that is empty; `ContentService` and `createTag()` had both said so since they had slugs at all. The `item` fallback stays, for the case it was written for: a name of nothing but punctuation.

## [0.41.0] &ndash; 2026-09-03

### Added
- **A date format setting, and a timezone beside it.** `date_format` takes PHP's date letters (`F j, Y` is *January 6, 2026*), `timezone` is a select of every zone PHP knows. The two arrive together on purpose: every timestamp is stored UTC, so a format on its own prints the UTC day - and a post published at half past midnight in Budapest was then dated the day before, for everybody.
- `$dates` in every template - `format()`, `iso()` and `tag()`, which writes the whole `<time datetime="...">` element so the printed date can read however the site likes while the attribute still says what it means.
- **`$authors` on every listing and `$author` on a post**, so a byline is something a template can write. Keyed by content id, **one query for the page**: twenty posts by three people is three names. A name rather than the `User`, because handing a template the entity hands it an email address to print by accident.
- `UserService::findByIds()`.

### Notes
A bad timezone falls back to UTC rather than throwing - a settings screen can be typed into, and a clock setting that is a typo should show the wrong hours rather than an error page on every URL the site has. The same bargain a missing theme makes.

## [0.40.2] &ndash; 2026-09-03

### Added
- `ThemeAssets::url($file, versioned: false)`, for an asset **named** after its own contents. A font is the case: a `url()` inside a stylesheet carries no version because a stylesheet cannot know one, so a `<link rel="preload">` built with `?v=` is a different URL from the one `@font-face` then asks for - the browser downloads the font twice and the preload helps nothing.

## [0.40.1] &ndash; 2026-09-03

### Fixed
- **A menu item that is only an external address now renders.** The editor asks the kind in one select and the thing in another, and nothing checked that they agreed - so leaving *Points at* on its default and filling in *Address*, which is the obvious way to add an external link, saved a post link with no post. It is refused now, with the reason and what to do about it.
- **A target of the wrong kind no longer becomes the wrong target.** The select carries its kind in the value (`12`, `c12`, `t12`) and `ltrim($value, 'ct')` stripped that regardless of the kind chosen, so a tag picked under *A category* pointed the item at the category with that id - silently, at a URL nobody had chosen.
- **"Not rendered - its target is gone" told you the wrong thing** for an item that never had a target, sending you looking through the bin for a post that was never there. An item with nothing chosen, one whose target was deleted, and an external address with no address now read differently.

## [0.40.0] &ndash; 2026-09-03

### Added
- **Featured posts.** Tag a post with whatever `featured_tag` names (`featured` by default) and the front page puts it at the top, as `$featured_posts`, newest first and at most five. A tag rather than a column: an author already knows how to tag a post, un-featuring is removing one, and there is no new screen and no migration behind it.
- **They are left out of the list below**, through a new `exclude_ids` on the `content_list` query. Pinned at the top *and* repeated four rows down reads as a bug rather than as emphasis.
- **`$thumbnails` on every listing**, keyed by content id, in **one query for the page** (`MediaService::findByIds()`). A listing row carries `featured_media_id` and not the item, so a theme that wanted a picture on a card had the id and nothing to do with it; the built-in list template prints one now too.
- **Numbered pagination** on a body written in pages - `1 2 3 4 5` rather than only two arrows, from a new `page_urls`. On a seven page article "Next" alone means reading four pages to reach the fifth.
- `$site_description` reaches a template. It has been a setting, and editable, since settings existed, and no template could print it.
- `ContentService::findByTag()` takes listing options, so a tag can be ordered and limited like anything else.

### Notes
All of this is what porting a real design asked for, and it arrived in that order: the gopherlab.net theme is thumbnails-first on its front page and could not be built without them.

**`$featured_posts`, not `$featured`** - a single post's template has had `$featured` for its own picture since there were templates, and one name meaning two things is a bug a theme author writes once and debugs twice.

## [0.39.0] &ndash; 2026-09-03

### Added
- **A theme may have a layout per kind of page.** A front page and a post being read are not the same document, so a theme writes `layout-home.phtml` beside its `layout.phtml` and the front page renders through it. Five kinds are named — `home`, `archive`, `post`, `page`, `auth` — and **having the file is the registration**: a kind with no template behind it falls back to the one layout, so naming five costs a theme nothing until it wants a second.
- `$layout_kind` in every front-end template, printed as a class on `<body>` by the built-in layout — for a theme that wants one layout and two shapes of it rather than two files.
- **A theme's own `assets/` folder, served at `/assets/theme/<file>`**, through `$theme->url('style.css')`. Cache-busted by the theme's version from `theme.ini` rather than the CMS's, immutable headers, an allowlist that covers fonts and pictures as well as CSS, and the active theme's files only — the name is not in the URL.
- **`theme:`, a namespace for the templates that are the theme's own** rather than overrides of the CMS's — `theme:partial/head`, so two layouts can share one header without a theme claiming a name under `dpress:` for a file the CMS does not ship.
- [docs/themes.md](docs/themes.md).

### Fixed
- The example theme printed `$footer_menu`, a variable nothing has ever set, so a menu assigned to the `footer` place it declares rendered nowhere. It renders `$places->render('footer')` now, like every other place.

### Notes
**A place only one layout renders is a place that only appears there** — `sidebar` beside a post and not on the front page, `home_top` on the front page and nowhere else. That is the cheap version of block visibility rules, with nothing to configure and no grammar to design, and it arrives as a consequence of two layouts rather than as a feature. What it will not express is a condition finer than which layout.

Nine templates used to name `dpress:layout` outright, so a theme wanting a second layout had to override all nine to alter one string. `AbstractController::render()` now takes the kind and the templates render through a variable.

---

## [0.38.0] &ndash; 2026-09-02

### Added
- **A body with more than one `---` is served a page at a time**, with *Previous* and *Next* under it, on posts and on pages. The first separator has always ended the lead; every one after it now ends a page — no new syntax, no new column, and nothing to set on the editor.
- `?page=2`, and **page one is the post's own address with nothing appended**. A number that is not there is a 404 rather than a clamp, so one post cannot be indexed at every number there is.
- `views/content/pages.phtml`, which a theme can override and which renders nothing at all when there is one page.

### Fixed
- **A `---` inside a fenced code block is no longer a separator.** A post explaining YAML front matter was cut in half at the exact place its author was writing about — the lead/body split reads the same lines and had the same hole, so both are fixed by one fence-aware scan.

### Notes
What is stored is still one column: `body_html` with a `<!--dpress-page-->` marker between the pages, cut by `ContentPages::split()` behind a `str_contains` guard — the same guard the shortcodes and the highlighter use, so a site that never breaks a post pays nothing on a page view.

**Each page is rendered on its own**, never one render cut up afterwards, because cutting HTML in half is how a `<ul>` ends up with no closing tag. It costs what the lead/body split already cost: a reference-style link defined on one page cannot be used on another. One good thing falls out of it — the highlighter loads on the page that has the code block and on no other.

The lead is on page one only: it is the opening of the article, not a header repeated above every part of it.

`body_html` is a cache, so run `dpress content:rerender` to break up posts written before this.

---

## [0.37.1] &ndash; 2026-09-02

### Fixed
- **A tag keeps its own colour after somebody has visited it.** `a:visited` is `(0,1,1)` and `.tag` alone is `(0,1,0)`, so the link colour won and a cloud came out half pink — for a reason that is nobody's business. A pill is chrome, like the header links, rather than a link in prose, so `:visited` is now stated with it.

---

## [0.37.0] &ndash; 2026-09-02

### Added
- **Blocks: a tag cloud, a category list and a piece of markdown, in a place beside the content.** `/admin/blocks` is a table per place, dragged into order with the handle the trees use. The built-in layout gains a **sidebar** — two columns above 900px, stacked below it — and renders nothing at all when a site has no blocks.
- **The markdown block is the general one.** `[![Buy me a coffee](media#14)](https://ko-fi.com/name)` is the case it was asked for: an image reference that survives the site moving, the same editor and media picker as anywhere else, and `{{ video('media#10') }}` working in it with nothing added.
- A block type is a **registration** — `Blocks::add()`, the call `Shortcodes` and `FormWidgets` already gave plugins. `fields` describes its settings as form fields, `prepare` is an optional save-time hook, `render` returns HTML.
- `block.view` / `block.update`, the `block_list` query, `block:saved`, `block:deleted` and `block:before_render`.
- `dpress content:rerender` now re-renders blocks as well.

### Changed
- **A place is one idea.** A theme's `places[]` is offered to both editors: a menu is assigned to a place, blocks are ordered in one, and whatever is in a place renders there. The built-in templates declare `main` and `sidebar` and render blocks in both.
- `TreeOrder` grew `moveFlat()` and `renumberFlat()`, so a list that does not nest reorders through the same renumbering rule rather than a second copy of it.

### Notes
The settings live in **one JSON column** rather than a column per type, and that is the decision the rest follows from: a new kind of block is a registration, never a migration — which is the only way a plugin can add one at all. It also means the editor never branches on `type`, the mistake `FormWidgets` was built to take out of form rendering.

Like a post, **a markdown block is rendered when it is saved**. A page view prints HTML and parses nothing, which is why `content:rerender` had to grow: a block holding `media#14` has exactly the problem a post holding `media#14` has when the site moves.

**A site with no blocks pays nothing**: the layout asks the place, the place answers `''`, and no query is made. A place with three in it costs two queries plus whatever the blocks ask for — about 1 ms on the development site.

**Blocks are not audited**, like menus: arranging a layout is moving things about, and a revision per drag is churn rather than history.

Per-block visibility rules — "posts only", "home only" — are deliberately left out for now.

---

## [0.36.1] &ndash; 2026-09-02

### Changed
- The quote mark on a blockquote is **stroked** like the other three icons rather than solid. At `.55` opacity a filled grey glyph on a dark background loses far more of itself than a 2px line does, so it read as faint next to icons drawn at the same size.

---

## [0.36.0] &ndash; 2026-09-02

### Added
- **`> [!WARNING]` turns a blockquote into a panel.** Info, warning and danger, with a plain quote as the fourth. GitHub's five markers are all understood — `NOTE`, `TIP` and `IMPORTANT` read as info, `WARNING` as warning, `CAUTION` as danger — plus `INFO` and `DANGER`, which are what people guess.
- Each panel is a left border in its own colour, a tenth of that colour as background through `color-mix`, and an icon. Four custom properties: `--quote`, `--info`, `--warning`, `--danger`.

### Changed
- A plain blockquote is **grey** rather than pink, and carries a quote mark.

### Notes
**The syntax is valid CommonMark either way**, which is the whole reason for choosing it. Anywhere without dpress — a README, an editor preview, a document exported from here — it is still a blockquote, still readable, with a visible `[!WARNING]` where the styling would have been. A convention that only works inside one CMS breaks the moment a document leaves it.

It also means **the content is markdown**: bold, links, lists and code work inside a panel because CommonMark parsed them before any of this ran. A shortcode could not do that — `{{ warning('…') }}` takes a string, and a panel holds prose.

**The icons are CSS**, data URIs on a `::after`, for the reason the syntax highlighting keeps its colours out of `body_html`: an icon is presentation, and presentation in stored content means `content:rerender` over every post to change it. What is stored is `<blockquote class="callout callout-warning">` and nothing more.

The marker is only a marker at the very start of the quote, so `> See [!WARNING] in the docs` stays a sentence; an unrecognised one is left exactly as written.

---

## [0.35.5] &ndash; 2026-09-02

### Fixed
- **A post's title was smaller than the headings inside it.** `h1` is 20px and a markdown `##` had no rule at all, so it fell to the browser's `1.5em` — about 22.5px. Every post read as though it began in the middle. `article` now carries a scale, 28 / 21 / 17, scoped so the 20px `h1` on a login or a form card is left alone.

---

## [0.35.4] &ndash; 2026-09-02

### Changed
- **A blockquote has a 4px left border in `--link`**, a quarter-black background and half an em of vertical padding. Its margin is reset with it: a browser indents a blockquote 40px on the left, which would have left the new rule floating in the gutter rather than marking the column.

---

## [0.35.3] &ndash; 2026-09-02

### Changed
- **The dark scheme's link is `#ff79c6`** — Dracula's own pink, so a link and the default code theme agree. **7.5:1**, past WCAG AAA. `#d4006b` stays on light at 5.2:1.

---

## [0.35.2] &ndash; 2026-09-02

### Changed
- **A link colour per scheme**: `#d4006b` on light, `#ff79aa` on dark, both through `--link` — the dark block redefines the one property and nothing else changes.

### Notes
Measured: **5.2:1** and **7.3:1**. Both past WCAG AA for body text, and the dark one past AAA. A pink that reads well on near-black is faint on white and the reverse, which is what one value could not do.

---

## [0.35.1] &ndash; 2026-09-02

### Changed
- **The link colour is `--link`**, one custom property at `:root`, so changing it is one edit.
- **`#fe71af`**, and the breadcrumb and meta links use it too — they were grey.

### Notes
On the dark background this is a clear improvement: about **7.1:1** against **4.7:1** for `#ff0071`. On the white card it is **2.5:1** against the old **3.8:1**, so it reads worse there, not better — a lighter pink helps dark mode and hurts light mode, and my note about contrast was about white specifically.

The variable is what makes having both trivial: a second `--link` inside the existing `prefers-color-scheme: dark` block, with something nearer `#d4006b` at `:root`, would give each scheme a value that passes. Left alone because the colour was asked for by name.

`.links a` — the log in and register links under a form — stays grey, and so does the header, which sets its own on a dark bar.

---

## [0.35.0] &ndash; 2026-09-02

### Changed
- **Dracula is the default code theme.** Only for a site that has never chosen one — an existing setting is left alone.
- **Links in the built-in templates are `#ff0071`**, visited included. `:visited` is stated separately because it is the more specific match and would otherwise keep the browser's purple whatever `a` said — the same thing that bit the admin in 0.30.1.

### Notes
Only links in the text change. The header, the menu, the breadcrumb and the meta lines each set their own colour and are more specific than a bare `a`, so their deliberate whites and greys stay — say the word if those should be pink too.

`#ff0071` on the white card measures about **3.8:1**, which is under WCAG AA's 4.5:1 for body text; on the dark background it is about 4.7:1 and passes. Worth knowing rather than worth changing on my own — it is a brand colour, and a slightly darker pink for light mode only would fix it if it matters.

---

## [0.34.2] &ndash; 2026-09-02

### Fixed
- **A highlighted block was showing its own `<code>` tag as the first line of the code.** EnlighterJS reads the `innerHTML` of the element it matched and unescapes it, so the `<code class="language-php">` wrapper was not ignored — it was displayed, tag and all. Its documented markup is a `<pre>` with the code directly inside, and that is what a block with a language renders to now. A fence with **no** language keeps `<pre><code>` exactly as before.
- **Every inline `` `code` `` span in prose was being rebuilt as a code sample.** `EnlighterJS.init(blocks, inline, options)` — the second selector is for *inline* snippets, and it was `code`. It now matches nothing.

### Notes
Both were mine, shipped in 0.34.0, and both were found by somebody reading the page rather than by a test. There are tests for each now, and the first one fails against the version that shipped.

`class="language-php"` moved to the `<pre>` with the code, so a document leaving this CMS still says what its code is written in.

**Existing posts need one `dpress content:rerender`.**

---

## [0.34.1] &ndash; 2026-09-02

### Fixed
- **A code block has room inside it.** Every EnlighterJS theme paints the background on `.enlighter-default` and sets `padding: 0` there, so the first line of code sat against the top edge of the colour and the last against the bottom. One rule corrects it, emitted straight after the theme's stylesheet — both selectors are a single class, so source order decides it, and the theme link is added after the layout's own `<style>`. Vertical only: the code area beneath is a `display: table` and horizontal padding there fights the line layout.

### Notes
The correction lives in `CodeAssets` rather than in a layout, so a theme author does not have to know it is needed and there is one copy for all thirteen themes. The vendored file stays unmodified, which keeps the MPL obligation to a notice.

---

## [0.34.0] &ndash; 2026-09-02

Syntax highlighting.

### Added
- **` ```php ` colours the block.** Thirteen themes, chosen in Settings, with *No highlighting* among them. [EnlighterJS 3.4.0](https://enlighterjs.org) (MPL-2.0) vendored in `assets/enlighter/`, served from this site rather than a CDN — a visitor reading somebody's code sample should not be announced to a third party for it.
- An alias table for the names people actually type: `c++`, `c#`, `js`, `py`, `yml`, `bash`, `html` and the rest. Anything not in it is passed through, so a language the highlighter learns later needs no change here.
- `GET /assets/enlighter/?` — the only asset a visitor ever loads.

### Notes
**None of it is stored.** A block renders to `<pre data-enlighter-language="php">` and the colours happen in the browser. A server-side highlighter would write a `<span>` per token into `body_html` — markup about how a thing looks, living inside the content, which is the mistake `media#12` exists to avoid one level down. It would also mean re-rendering every post to change a theme, and again to upgrade the highlighter. As built, both change every page at once and touch no document.

**A page with no code block loads nothing.** The front end ships no JavaScript, and that stays true everywhere it can — one `str_contains` decides, the same guard the shortcodes use. Without JavaScript a code block renders plain and correctly escaped, which is what it did before.

**Off is the word `none`.** `SettingService::get()` reads `''` as *absent* and answers with the default, so an empty setting would mean "the default theme" rather than "no highlighting" — found while testing it. A theme name that is not one of the thirteen is treated as off rather than guessed at, because a stylesheet path is not something to build out of whatever is in a row.

Posts written before this need one `dpress content:rerender` to gain the attribute. That is the only migration this feature will ever need.

---

## [0.33.0] &ndash; 2026-09-02

### Added
- **`{{ video(…) }}` takes a YouTube or Vimeo link.** `watch?v=`, `youtu.be/`, `/embed/` and `m.youtube.com` for YouTube; `vimeo.com/<id>` and `player.vimeo.com/video/<id>` for Vimeo. A `?t=90` on a share link survives as the player's `start`, because a timestamp is the one thing somebody took care over.
- The player is served from **`youtube-nocookie.com`**, which is the same player and stores nothing until somebody presses play. A CMS puts embeds on other people's sites for other people's visitors; where both work identically, the quieter one is the default.
- One `<iframe>` and no wrapper: `aspect-ratio: 16 / 9` lives in the stylesheet, so a theme can restyle it without unpicking a padding trick.

### Notes
**The host is matched at its end, not searched for.** `notyoutube.com` and `youtube.com.example.net` are not YouTube, and a `str_contains` would embed both — there is a test, and I checked it fails against the naive version.

A library reference and a direct video file still become a `<video>`; anything else still leaves a comment saying so rather than handing a `<video>` element a watch page that fails silently.

---

## [0.32.1] &ndash; 2026-09-02

### Fixed
- **A video is constrained to its column, the same as an image.** `img, video { max-width: 100%; height: auto }` in the built-in templates and in the example theme — a video is 1920 wide whatever the column is, so without it the `video` shortcode decided how wide the page was.

---

## [0.32.0] &ndash; 2026-09-02

Shortcodes.

### Added
- **`{{ name(arguments) }}` in a document**, resolved to whatever a registered handler returns. `{{ video('media#10') }}` ships; everything else comes from a plugin, through `PluginInterface::shortcodes()`. See [docs/shortcodes.md](docs/shortcodes.md).
- A small grammar: positional and named arguments, quoted strings, integers, floats, `true`, `false`, `null`. No nesting, no expressions — an evaluator running over text an author typed is a different thing to be responsible for than a lookup table.
- `BLOCK` and `INLINE`. A block shortcode alone in a paragraph replaces the paragraph, because a `<figure>` inside a `<p>` is invalid and a browser rearranges it rather than ignoring it.

### Notes
**A shortcode can be written about.** `` `{{ video('media#10') }}` `` renders those characters, and so does a fenced block — which falls out of `{{` being claimed by a **CommonMark inline parser** rather than by a regular expression over the markdown. A regex cannot tell a shortcode from one being quoted, and a CMS whose own documentation cannot be written in it has a bad idea in it. `\{\{` escapes too, with nothing of ours: `{` is ASCII punctuation.

**A shortcode runs on the page, not at save, and it is the one place that rule is broken on purpose.** A gallery's contents change without the posts embedding it being touched, and re-rendering everything that mentions one is the referrer-chasing that works for a rename and loses for a gallery. So `body_html` holds a **marker** carrying the call, and `Shortcodes::expand()` swaps markers for output in `AbstractController::render()` — over the finished page, once, because content HTML reaches a template from five places and a theme may render any of them.

The markdown is still parsed once, at save. What moved is only the shortcodes, and it is pay-per-use: **35.3 ms for a page with none against 36.4 ms for a page with one**, measured on the development site. A site using none pays for a `str_contains` over a string already in memory, which is why that guard is the first line of `expand()`. `docs/performance.md` says so with the numbers.

**The marker cannot be forged.** `html_input => 'strip'` means raw HTML never survives from a document into `body_html`, so only the parser writes one. The payload is base64 because an argument containing `-->` would end the comment early and spill the rest of the post onto the page.

An unknown name at **save** leaves the author's text exactly as typed, so a document can be written before the plugin providing its shortcode is installed. An unknown name at **view** — the plugin switched off this morning — leaves an HTML comment and a logged warning, which is what `FormWidgets` does for an unregistered field type. A revision shows the marker rather than the output, which is right: history should show what the author wrote.

**No theme shortcodes.** A theme is data and templates; a shortcode is code. A theme that wants one ships a companion plugin.

---

## [0.31.4] &ndash; 2026-09-02

### Fixed
- **An image in an article can no longer be wider than the article.** The built-in templates constrained the featured image and nothing else, so a photo straight off a phone decided how wide the page was. `img { max-width: 100%; height: auto }` covers everything a document can contain — `max-width` rather than `width`, so a small image stays its own size instead of being blown up to fill the column. The example theme had the same gap.

---

## [0.31.3] &ndash; 2026-09-02

### Fixed
- **An anchor styled as a button keeps its own colour.** 0.30.1's `.admin-main a { color: inherit }` is (0,1,1) against `.button`'s (0,1,0), so it won: every Back and Cancel took its colour from whatever it sat in rather than from `--ink`. Nothing looked wrong because no button currently sits in a coloured container — luck rather than design. `.button.primary` is (0,2,0) and was never affected, which is why Upload and New always matched.

---

## [0.31.2] &ndash; 2026-09-02

### Fixed
- **A checkbox in a filter form is no longer 160px wide.** `.filters input` set a minimum meant for the things somebody types or picks in, and caught the media list's *Show deleted* along with them. `label.inline input` set `width: auto` but not `min-width`, which is why it survived; the minimum now excludes checkboxes and radios at the source.

---

## [0.31.1] &ndash; 2026-09-02

### Added
- **A `#` column on the categories screen**, for the reason the content list has one: `category#21` in somebody's markdown is written by hand as often as it is inserted by a button.
- `dpress_admin:tree` takes `align` and `width` on a column, and tags every cell with `data-property` — the same vocabulary the dynamic lists use, so the id renders muted and tabular there too. The stylesheet rule that did that was scoped to `.dynamic-list`; it now reaches both kinds of table.

### Notes
The menu items screen has no `#` column, and that is not an oversight: a menu item is not something anything refers to by id. A category is.

---

## [0.31.0] &ndash; 2026-09-02

### Changed
- **Deleting is a trash icon on the row again, with a confirmation, on every list.** *Delete selected* is gone from content, media, tags, menus, users and roles, and with it the checkbox column and the bar above every list — the checkboxes disappear on their own, because a list with no group actions has drawn none since 0.25.6.
- **The plugins screen keeps its group actions.** Enabling six plugins at once is a real act; deleting six things at once is a mistake looking for somewhere to happen.

### Removed
- The six `/delete-selected` endpoints, and `deleteSelected()`, `deletedNotice()` and `actionIds()` in the admin base, which had no callers left. The plugins screen reads its own ids, because a plugin is named by its folder rather than by a number.

### Notes
This reverses 0.21.0, which took the row deletes out and put a selection in. What decided it back: a bulk delete costs a checkbox column on every screen, forever, for the most dangerous operation in the admin behind a single confirm — and the one case that genuinely wants it, a library full of files uploaded by mistake, is the case a CLI already answers better. `media:delete` takes an id and a shell takes a loop.

Every `/delete/?` endpoint was already there. Only the buttons ever moved.

---

## [0.30.2] &ndash; 2026-09-02

### Changed
- **The menu item's label opens the item**, and the edit icon beside it is gone — the rule the dynamic lists have followed since the row actions went: *the edit buttons are not needed, as the click on the name goes there.*
- **The categories screen lost its edit icon too.** Its name was already a link, so the icon was a second button for the same thing; leaving one screen with it and one without would have been the odd part. Both keep their per-row delete.

---

## [0.30.1] &ndash; 2026-09-02

### Fixed
- **Admin links are the ink colour, not the browser's blue and then purple.** The dynamic lists had `.dynamic-list td a { color: inherit }` and nothing else did, so the two tree screens and the media editor's file link fell back to the browser default and turned purple as somebody worked down them. One rule covers every screen now, and the list-only one is gone as redundant. `:visited` is stated alongside `a`, because it is the more specific match and would otherwise keep the browser's colour whatever `a` said.

---

## [0.30.0] &ndash; 2026-09-02

### Added
- **`dpress_admin:tree`** — the counterpart of `dpress_admin:list`, and the same bargain: a tree screen writes one array and no markup. Columns (`tree` for the indented one, `view => 'html'` for markup the controller built, `link` to make it a link), row actions (`link` or `post` with a confirm), and where a drop posts to.

### Changed
- **The menu items and categories screens render through it.** The two templates were copied from one another, down to the categories table carrying `class="menu-items"`, and copied markup drifts. Between them they lose 109 lines and gain 67, and both are now a page head and one `fetch()`.
- `table.menu-items` is `table.tree-table`, which is what it always was.
- A tree screen with no drag permission renders no handles and no `data-sortable-tree`, rather than handles that post to an endpoint that would refuse them.

### Notes
The behaviour was already shared — `Dpress.sortableTree()` reads `data-id`, `data-parent` and `data-depth` and knows nothing about menus or categories. It was only the markup that was duplicated, which is the half that rots quietly.

`TreeTableTest` pins what a config-driven table can still get wrong: a column with no field behind it is a row of blanks with no error and no warning, and a row action naming a property nothing carries renders no button at all. Both screens are checked, the way the attachments panel is and the way the dashboard was not until it had been broken for three releases.

---

## [0.29.0] &ndash; 2026-09-02

### Changed
- **The categories screen is a tree table, dragged into order.** The same handle, the same three drop zones and the same `TreeOrder` behind it as the menu items screen, over `POST /admin/categories/move/?`.
- **What that cost, deliberately:** the search box, the sortable columns, the pager and **Delete selected**. A dynamic list is a table somebody searches; a tree is something somebody arranges, and dragging a row means nothing while the rows are sorted by name or split across pages. The two cannot both be true of one screen, so the screen picked one. Each row keeps its own Edit and Delete, the way the menu items screen always has.
- The whole tree renders. A page of a tree is not a tree.

### Removed
- `GET /admin/categories/list`, `POST /admin/categories/delete-selected`, `TaxonomyAdminController::CATEGORY_SORTABLE` and the paged row builder behind them — all unreachable once the screen stopped being a list, and an endpoint nothing can reach is worse than one that is gone.

---

## [0.28.0] &ndash; 2026-09-02

### Added
- **Menu items are dragged into order.** A two-line handle replaces the Position column, and a drop both reorders and re-nests: the top quarter of a row drops **before** it, the bottom quarter **after** it, and the middle **inside** it. That vocabulary can express any move in a tree, which is why there are three zones and not two.
- `Dpress.sortableTree()` — pointer events rather than HTML5 drag and drop, which cannot say where *inside* a row the pointer is without a handler on every row, drags a ghost image nobody asked for, and behaves differently on a table row in every browser. The rows carry the tree in `data-id` / `data-parent` / `data-depth` and the module reads nothing else, so the same code will drive the categories screen.
- `Content\TreeOrder` — moving a node around any `parent_id` + `position` tree, with `MenuService::moveItem()` and `TaxonomyService::moveCategory()` over it, and `POST /admin/menus/items/move/?`.

### Notes
**Positions are renumbered, not nudged.** A drag says "put this under that, third"; what comes back out is `0, 1, 2, …` with no gaps, on both the sibling row the node left and the one it joined. Nudged positions drift into `0, 3, 3, 7`, where ties fall back on insertion order and nobody can see why the list looks like it does.

**A node cannot be dropped inside its own branch**, checked to any depth on both sides — the browser will not offer it and the server refuses it anyway. Without that the branch is still in the table with a parent chain that loops, so nothing walking down from the top ever reaches it again: gone from every screen while still being there.

The move is applied to the table before the server has answered, because the server renumbers from the same order and agrees. If it does not — a refused move, a lost connection — the screen is showing something that did not happen, so it asks for the screen again rather than guessing its way back.

**The categories screen still has its Position column.** It is a flat, searchable, paginated list with a group delete, and dragging cannot mean anything while it is sorted by name or split across pages. Making it draggable means making it a tree table like this one, and what that costs is set out in the notes for the next release.

---

## [0.27.4] &ndash; 2026-09-02

### Fixed
- **The menus screen said a menu had nowhere to render, on a site that was rendering one.** A theme declares its places in `theme.ini`; the built-in templates have no `theme.ini`, so with no theme active `ThemeService::places()` came back empty — while `views/layout.phtml` was putting `main` beside the logo, as it always has. `ThemeService::BUILT_IN_PLACES` writes that down, and a theme whose folder has gone missing falls back to it along with the templates. The list's Place column now reads **Main** rather than *main (not in this theme)*, and the editor's select offers it.

### Notes
The templates and the manifest were two places one fact could live and only one was being read. The warning itself was not wrong — a theme that exists and declares no places really does render a menu nowhere, and it still says so.

---

## [0.27.3] &ndash; 2026-09-02

### Fixed
- **One filter change asked the server twice.** A `<select>` fires `input` and then `change`, and `bindFilters()` had a separate listener on each — so choosing a category on the media page sent one request at once and an identical one 250 ms later. Two more of the same shape went with it: a text field fires `change` on blur as well as `input` while typing, so tabbing out of a search box asked again for what was already on screen; and `DynamicList` binds `submit` itself when it is handed a form, so pressing Enter went through two handlers.
- One timer that every event reschedules, and a guard on what was last asked. Typing waits 250 ms, anything else goes at the end of the tick — late enough for a select's two events to collapse, soon enough to feel immediate. `submit` is not bound in `bindFilters()` at all now; the list already has it.

### Changed
- **The media picker dialog uses that same binder.** It had its own debounced `input` handler doing nearly the same job slightly worse: a select there waited 250 ms and an unchanged filter was asked for again. `debounce()` has no callers left and is gone.

---

## [0.27.2] &ndash; 2026-09-02

### Fixed
- **`hidden` now actually hides.** The markup already marked the media field's Remove button hidden when nothing was chosen; the browser's own `[hidden] { display: none }` is an author-beatable rule, so `.button { display: inline-block }` quietly overrode it and the button showed anyway. One `[hidden] { display: none !important }` in the admin stylesheet fixes it — and with it the media picker's upload error, which `.form-error { display: block }` had been overriding in exactly the same way.

---

## [0.27.1] &ndash; 2026-09-02

### Fixed
- **A media field with nothing chosen no longer opens with a gap.** The preview is a flex item, so an empty one still takes a slot and its `gap` in front of the Choose… button. Hidden with `:empty` — which needed the template emitting the element on one line, because a newline inside it is a text node and a text node is not empty. There is a comment saying so, since tidying that line back onto three would put the gap back and nothing would say why.

---

## [0.27.0] &ndash; 2026-09-02

### Changed
- **The site logo and the favicon are chosen from the media library.** `site_logo` and `site_icon` hold a media id and use the same picker every other media field does. This replaces the `asset` field of 0.26.0, which kept the value a path and put a Choose… button beside a text box — that answered a docblock rather than the request, and it is gone: the widget, its template, its binder, `MediaView::sitePathOf()` and the `site_path` on media rows.
- **A fallback is what makes that safe.** `AbstractController::brandingAsset()` renders the chosen item when there is one and it is still in the library, and `dpress.default_logo` / `dpress.default_icon` when there is not. Never chosen, deleted, purged and a fresh installation all take that one branch — there is one way for this to be missing rather than four. **Soft-deleted counts as gone**: something in the bin should leave the header rather than wait for a purge.
- The defaults are paths, resolved against `app.base_url`, and **empty in core** — dpress ships no logo and cannot know what a site keeps in its own `static` folder. The development app sets them in `dpress.ini`.

### Notes
The concern the old design protected against was real; what it needed was a fallback, not a path. Every property is still held: the header renders before anything has been uploaded, on pages with no content on them, and deleting a picture cannot take it down.

An existing `site_logo` holding a path is not a media id, so it resolves to the default — which on a site whose default is that same path changes nothing visible. Anything pointing somewhere else needs choosing again.

Costs two primary-key lookups per render when both are chosen: 33.8 ms against 34.4 ms with neither, so inside the noise and not worth caching.

---

## [0.26.0] &ndash; 2026-09-02

### Added
- **The media picker fills the site logo and favicon.** A new `asset` field type: a text input with a **Choose…** button beside it, a preview, and a Remove. Registered through the same call a plugin uses, so it is available to one.
- `MediaView::sitePathOf()`, and a `site_path` on every media row — the file named from the site root rather than from the internet.

### Notes
**The value is still a path, not a media id**, which is why this is its own field rather than the existing `media` one. `Setting::SITE_LOGO` documents the reason and it still holds: a logo is chrome, it renders on pages that show no content at all, it has to work before anything has been uploaded, and deleting a library item must not be able to take the header down with it. So the picker fills a text box rather than replacing it — `/static/logo.svg` is still typeable, and so is a URL on another host.

What the picker writes is **site-relative** (`/uploads/2026/09/fox-6641fe.mp4`), never absolute. An absolute URL would put the machine that chose it into a setting a production site reads, which is the exact thing `media#<id>` exists to avoid everywhere else.

The widget test now walks `DpressServices::WIDGETS` instead of a list written out beside it, so the next field type is covered the moment it is registered.

---

## [0.25.7] &ndash; 2026-09-02

### Removed
- **The Title column in the media library and the picker dialog.** Found by sweeping every admin list's declared columns against the fields its endpoint actually returns: nothing was broken anywhere, but this one was empty on every row, because a media title is optional and rarely set. The `title` **field** stays in the payload — `Dpress.insertMedia()` uses it as the alt-text fallback when writing a link — and it stays sortable, editable and on the media form.

---

## [0.25.6] &ndash; 2026-09-02

### Fixed
- **A list with no group actions no longer draws a checkbox column.** The checkboxes exist to feed a group action, and `[]` is not falsy — `Dpress.list()` passes exactly that for a list that declared none, so the column was drawn with nothing anywhere to do with a selection. Two places had it: the **attachments panel**, which declares no group actions at all, and **any list whose only group action is behind a permission the viewer lacks** — the stock `editor` role holds `page.view` but not `page.delete`, so an editor saw a select-all and a checkbox per row on the Pages list, both inert.

---

## [0.25.5] &ndash; 2026-09-02

### Fixed
- **A column asking for no heading got its property name instead.** `label: ''` says "no heading" — a thumbnail, a checkbox — and the header fell back with `column.label || property`, which reads an empty string as "nothing given". So the media library, the picker dialog and the attachments panel all had `thumbnail_html` written across the top of a column of pictures. An explicit empty label is honoured now; a missing one still falls back.

### Changed
- That column is labelled **Icon** in all three lists, which is what it shows and short enough not to set the width.

---

## [0.25.4] &ndash; 2026-09-02

### Fixed
- **"When" and "What" were blank on every history screen there has ever been.** The template read `$revision['changed_at']` and `$revision['operation']`; `revisions()` selects `rev_at` and `rev_type`. The same two names, wrong in the same way, as the dashboard in 0.25.2 — six blank columns between them, none of which raised anything, because a missing array key renders an empty cell.
- The test that pinned this for the dashboard now covers **every template that renders revision rows**, and expands a `select a.*` so it can check a query that names no columns at all. Found by opening the screen and looking, which is twice now; there is a test for it instead.

### Changed
- **Every admin screen is the same width.** Media edit, media upload, settings, and the menu, taxonomy and user editors were capped at 680px while lists and the content editor ran full width. The `narrow` flag is gone — from the controllers, from `AbstractAdminController::admin()`, from `main.phtml` and from the stylesheet — rather than left as a mode nothing selects.

---

## [0.25.3] &ndash; 2026-09-02

### Removed
- **The Reference column in the attachments panel**, and the `ref` field behind it. It existed so `media#<id>` could be read off a row and typed into the text by hand, back when attaching a picture was how it got into an article. It is not any more: the toolbar's 🖼 button writes the reference and attaches nothing, and the list's insert action builds it from the row's id. To write one out by hand the ids are in the media library, which has a `#` column like every other admin list.

### Changed
- The test that pinned that column now pins **the agreement instead** — every column the panel declares has a field the rows carry. A column with no field is a row of blanks with no error and no warning, which is exactly how the dashboard stayed two-thirds empty for three releases. The Reference column could then be removed without touching the test, and the next column added is covered the moment it exists.

---

## [0.25.2] &ndash; 2026-09-02

### Fixed
- **The dashboard's "Recent changes" was a stack of empty lines.** Two separate faults, one of them three releases old. The template read `$revision['changed_at']` and `$revision['operation']` while `recent()` selects `rev_at` and `rev_type`, so **two of the three columns had always been blank** — a missing key renders an empty cell, with no error and no warning, which is why nobody reported it. What made it visible was the third column going blank too: an auto-draft has no title, and ten of them at the top of the list left nothing but the row borders. `recent()` now leaves auto-drafts out, since opening "New" is not a change worth reporting.
- There is a test now that reads the template, pulls out every column it asks for, and checks `recent()` actually selects it. Nothing connected the two before.

### Reverted
- **"No attachments yet." is back, and so is the line under it** — 0.24.0 took the message out and 0.25.1 hid the empty list's footer with it. Hiding the footer was general, so it applied to every list, and an empty panel with no message and no rule reads as something failing to load rather than as something being empty.

---

## [0.25.1] &ndash; 2026-09-02

### Fixed
- **A list with no rows no longer draws a line under whatever is above it.** The footer is always in the markup and carries a top border and its padding, so an empty one is not empty on the screen — it is a rule plus a strip of nothing. It is hidden now when it has neither a record count nor paging to show, which is the rule `.group-actions:empty` already followed. Most visible under the attachments panel, where an empty list leaves only the heading and the Add attachment button.

---

## [0.25.0] &ndash; 2026-09-02

There is no such thing as an unsaved post.

### Added
- **"New" writes a row and opens the editor for it.** An immediate write needs an id, and being told to save an empty post and come back before you may attach a file to it is a strange thing to be told. `POST /admin/content/<type>/new` calls `ContentService::startDraft()` and redirects to the editor — a POST, not a link, because it inserts.
- `Content::STATUS_AUTO_DRAFT`, **deliberately not in `STATUSES`** — that list is what the status select offers and what `assertStatus()` accepts, so the value cannot arrive from a form or from `content:create`. It is set in one place and left in one other: the first `update()`, which promotes it to a draft and makes the slug from the title.
- `CoreQueries::autoDraft()`, and an exclusion in `applyContentFilters()` so **nothing ever lists one** — the content list, the search, the "Parent page" select, all of them.
- `dpress content:prune [-days 7]`, which throws away the auto-drafts nobody came back to, attachments and all.

### Changed
- **`create()` is gone; `edit()` is the only editor.** That is the real prize: no screen has to answer "and what does this do before the post exists?" ever again. The attachments panel has no empty case, and the first save says *Created.* rather than *Saved.*
- The editor says **New post** while the row has never been saved, and offers no History for it — one revision recording that an empty row was made is not a history.

### Notes
**An auto-draft is reused, not remade.** `startDraft()` hands back the author's existing one for that type, so clicking New five times is one row, a file attached before wandering off is still attached on the way back, and the table is bounded at one row per author per type. That bound is why `content:prune` is a tidy-up rather than a cron.

**The form fills as if the post did not exist** — above all the placeholder slug. Offered back, it would be submitted as though it had been meant, and `auto-draft-3f9c1a2b4d5e6f70` would become the post's URL. Found while writing the change; there is a test for it.

The honest cost is two tabs: open New twice, save the first, and the second tab is now editing the post the first one made. Nothing is lost — every save is a revision — and it is the same surprise as two tabs on one post, which this CMS has never guarded against either.

---

## [0.24.0] &ndash; 2026-09-02

Attachments are files, not an index of the article.

### Changed
- **Putting a picture in the text attaches nothing.** The toolbar's 🖼 button picks a library item and writes `![alt](media#12)`, and that is the whole of it. A reference is all it takes to show a file, so the file need not also hang off the post — which means the button now works on a post that has **never been saved**, where it used to be disabled with an apology.
- **Attachments are the files somebody attached on purpose**, listed under the published page. "Add attachment" is the only thing that attaches.
- `MediaService::usageCount()` counts `media#<id>` in `markdown` as well as the attachment and featured rows, so deleting a picture an article shows still warns about it. A `like` candidate count on the same terms as `ContentService::referrerIds()`, and it counts **distinct content**: a post that attaches a file and shows it in the body is one thing that breaks, not two.
- An empty `noResults` in a list configuration now renders nothing at all rather than an empty box.

### Removed
- **`ContentAttachment.hidden`**, with `MediaService::setAttachmentHidden()` and `allAttachmentsOf()`, the `with_hidden` context on `contentAttachments()`, the **On the page** column, the Hide/Show row actions and `POST /admin/content/?/attachment-visibility/?`. The flag existed because inserting a picture used to attach it and an attached picture already in the article should not be listed under it as well; with nothing attached there is nothing to hide.
- The paragraph above the attachment list explaining all of that, and "Nothing attached yet." On a post that does not exist yet the panel does not render at all, instead of a heading and a disabled button that explain themselves away.

### Notes
**A distinction instead of a flag.** One table held two different things — files somebody attached, and images the text happens to show — and a boolean kept them apart. They are now two mechanisms that share nothing but the picker dialog, and the editor's list and the public list ask the same question and get the same answer.

The one thing genuinely lost was that an inline image no longer has a row saying it is in use, which is what `usageCount()` now goes to the markdown for.

**The `hidden` column goes out of `CreateSchema` rather than through a migration**, which needs the database dropped and recreated — the licence that holds until 1.0.0.

---

## [0.23.0] &ndash; 2026-08-06

Plugins.

### Added
- **A plugin is a folder under `plugins/` with a `plugin.ini` in it** — the theme rule, plus the two things a theme never needs: a namespace to autoload and a class to build. `AbstractPlugin` gives a no-op default to every contribution, so one that adds a single field type is four lines. See [docs/plugins.md](docs/plugins.md).
- **A plugin may contribute** services, controllers (routes come from their attributes by themselves), entities, migrations, form widgets, view folders, admin assets and permissions — plus `register()` for events and the two factories.
- **`/admin/plugins`**, behind `plugin.manage`, with Enable/Disable as group actions. Plus `plugin:list`, `plugin:enable`, `plugin:disable`.
- **`Dpress.addInit(fn)`**, so a plugin's widget can bind behaviour and have it run again after a partial navigation, and `/admin/assets/plugin/<name>/<file>` to serve its `.js` and `.css` — from its own folder, only while it is loaded, and only for a name with no slashes in it.
- `plugins/reading-time/` in the development app: an example exercising every extension point at once, which is what the whole thing is tested against.

### Notes
**Where the loader sits is forced from both ends.** After `DpressServices::register()`, because reading the enabled list needs the database, so a plugin cannot replace `Database` or `SettingService`. Before `initServices()`, because `Micro::get()` caches singletons forever, so it *can* replace `ContentService` with a subclass. And before `runMiddlewares()`, which is the single look `AttributeProcessor` takes at the container.

**Nothing in the loader throws.** Enabling is a setting and the screen that disables a plugin is in the admin, so a plugin that fataled on the way up would take away the only way to turn it off. Failures are caught and shown as *Failed* with their message; one breaking does not stop the next; an enabled plugin that is gone from disk is *Missing*; a database with no `setting` table yet simply has no plugins, so `dpress install` runs. `dpress.plugins_off = 1` boots with none of them.

**A failed plugin registers no controllers.** Found by testing it: the first version registered them before `register()` ran, so a plugin that threw left its routes live — a public URL running the code of a plugin that had just declared itself broken. They now go in only after `register()` returns. The container cannot unregister anything, so its widgets and permissions do remain; those are untidy rather than dangerous, and its entity and migration remaining is what keeps its table from being dropped out from under its data.

Measured, because `docs/performance.md` claimed "no plugin API to boot" as a reason dpress is fast and that stopped being true: **one enabled plugin costs about 1 ms, none costs nothing measurable.** The document now says so with the numbers.

**Needs micro 0.20.0.**

---

## [0.22.0] &ndash; 2026-08-06

The schema is one migration again.

### Changed
- **The eight migrations are squashed into `CreateSchema`** (`0001_create_schema`). Eight files describing a schema nobody had ever applied incrementally were eight files to read to find out what a table looks like, and the `alter` that added `ContentAttachment.hidden` is now just a column on the entity. One ordered list of tables, one call each — whether a table gets an audit mirror is the entity's own answer, because `createTableWithAudit()` builds one only where the class is `#[Auditable]`.

### Notes
**Every existing database has to be dropped and recreated.** That is deliberate and it is a pre-1.0 licence: dpress is on no public domain holding data anybody minds losing. **After 1.0 this stops** and migrations become append-only.

Verified by installing the squashed schema into a scratch database and diffing it against the real one: byte-identical except that `hidden` now sits where the entity declares it rather than appended at the end, which is cosmetic in SQL. The seeded roles and the editor's 23 permissions are identical too. The dev database was then rebuilt on it, with its content dumped and restored — 6 posts and pages, 6 media, 4 users, 3 menu items, 6 attachments, 82 revisions, all matching the dump row for row.

Three new tests guard what the squash gave up. While the schema was eight files, each one listed the group it created and the pairing was obvious from the file you were editing; in one list an entity can be registered, work, and only turn out to have no table on somebody else's fresh install. So: every registered entity has a table, nothing has a table that is not a registered entity, `Revision` is built first because every `_aud` mirror points at it, and no child table is built before what it points at.

---

## [0.21.0] &ndash; 2026-08-06

### Changed
- **The CMS field types are registered, not branched.** `markdown`, `media`, `checkboxes` and `permissions` now go through micro 0.20.0's `FormWidgets`, one template each under `views/widget/`, registered by `DpressServices::registerWidgets()` — which is exactly the call a plugin will use. `DpressForm::VIEW_INPUT` and `views/form-input.phtml` are deleted.

### Notes
That constant was a mechanism with room for **exactly one** contributor. The framework offered a single override for "the template that renders a field", the CMS spent it, and after that nothing could add a fifth field type without forking the CMS's own template and re-implementing all four branches. The registry has room for everybody, and the core now registers its types the same way a plugin does — a mechanism the core does not eat is a mechanism nobody notices breaking.

Verified against the running site: every widget renders on eight admin screens plus the two public forms, and nothing anywhere reports an unregistered type.

**Needs micro 0.20.0.**

---

## [0.20.1] &ndash; 2026-08-06

### Added
- **A `#` column on every admin list**, showing the row's id. It is what a reference in somebody's markdown is made of — `post#42` and `media#12` are written by hand as often as they are inserted by a button, and until now the only place to read an id off was the URL of the edit page.

### Notes
Sortable where the list actually sorts: `id` was added to the five sort whitelists, and `AdminTest::assertSortableAgainst` checks each of those names is a real column of the entity. The roles and the menus lists answer from a `page()` that takes no sort at all, so the column is declared unsortable there rather than given a header that does nothing.

None of the list queries join, so an unqualified `order by id` is unambiguous — worth checking, because `tag_cloud` does join and both of its tables have an `id`.

Verified against the running site: the column present and first on all eight lists, and sorting by it both ways on the five that sort.

---

## [0.20.0] &ndash; 2026-08-06

The list is for finding things. The editor is for changing them.

### Added
- **"Delete selected" on every list**, with the checkbox column the list has always been able to draw. Each row is tried on its own, so **one refusal does not abandon the rest**, and the page says what happened: `2 deleted. Your own account was left alone.` The same reason twice is one sentence. A row somebody else deleted in the meantime is not counted — reporting more deletions than happened is how a count stops being worth reading.
- `AbstractAdminController::deleteSelected()`, which every list's endpoint goes through. The rules stay with the controller that owns them: the callback answers `true`, `false` for nothing-to-do, or the sentence to show. Anything it throws is read as the third, so `UserService`'s last-administrator guard and `RoleService`'s protected roles need no special handling.
- A `htmlLink` column view, the counterpart of `link` for markup the server built — `link` escapes its text, which is right for a name and wrong for a thumbnail.

### Changed
- **The per-row Edit and Delete buttons are gone from every list.** The name cell was already a link to the editor in most of them; the rest were made to match. Deleting is selection plus one button.
- **Publish and Unpublish are gone from the content list too.** Status belongs to the editor's own control, which is where it can be seen next to what it applies to. The `/publish/` and `/unpublish/` endpoints stay — they are a reasonable thing for a script to POST to.
- **The media list's File name opens the item, not the file.** It is the only list where the name led somewhere else. The thumbnail is now the link to the file, so nothing became harder to reach.
- **`edit_url` is only sent when the person may edit.** It used to travel with every row while the button next to it was permission-checked, so the link was the loose one — and it is now the only way in. The column falls back to plain text without it. The administrator role, which is not editable, gets no link either.
- The menus list keeps its **Rename** button. Its name cell opens the items, which is what there is to edit about a menu, and that leaves renaming with no cell of its own.

### Notes
Media keeps **Restore** as a row action. It is the one thing here that is not a bulk operation: it only exists on a deleted row, and finding those is the work.

Group routes are `/delete-selected` rather than the single route with no id, because `/delete/?` and `/delete` are two different routes and a bulk POST arriving at the first would be a 404 after the confirm dialog said yes. `AdminTest` gained a test that all seven exist, and the "state-changing routes must be POST" test was fixed — its pattern matched `/delete/` and quietly skipped every one of the new ones.

Verified against the running site with a throwaway administrator account, since these are all POSTs behind a session: every list's configuration read back from the rendered page, a mixed selection of a real row and a missing one reporting `1 deleted.`, the self-delete guard, and the protected `admin` role surviving with its reason shown. The account and the test rows were removed afterwards.

---

## [0.19.0] &ndash; 2026-08-06

A document says what it points at, not where that is today.

### Added
- **Internal references in markdown: `media#12`, `post#42`, `page#5`, `category#21`, `tag#7`.** Written in a link or an image destination, they become the finished URL when the markdown is rendered. The stored document never mentions a hostname or a slug, so moving a site from a test domain to a real one is a change to `app.base_url` plus `dpress content:rerender`, and no stored markdown changes at all. `post`, `page` and `content` are one lookup — ids are unique across both types and the entity decides the shape of its own URL, so `post#5` naming a page still resolves. A trailing `#anchor` or `?query` is carried over.
- **`MarkdownRenderer::EVENT_ENVIRONMENT`.** The CommonMark environment is offered to subscribers before the converter is sealed, which is how the CMS reaches the renderer without the renderer knowing the CMS exists. It is also where a plugin would add tables.
- **The attachments panel shows the reference** in a Reference column, so it can be read off the row and typed by hand.
- **`TaxonomyService::categoryPath()` / `tagPath()`**, so `/category/<slug>` has one home rather than being spelled out wherever it was needed. `MenuService` uses them.
- `docs/internal-links.md`.

### Changed
- **The insert button writes the reference, not the URL.** Both writers — the toolbar's 🖼 button and the attachment row's insert action — go through `Dpress.insertMedia()`, which now produces `![alt](media#12)`. Documents written before this still hold absolute URLs and go on working as ordinary links; nothing converts them, because that would be rewriting everybody's documents to fix a problem they may not have.
- **A rename re-renders what links to it.** `ContentService::update()` notices when a slug or a `parent_id` moved and re-renders every document whose markdown mentions that id. A page moves more than itself, so its descendants count as moved too. The candidate query is a `like`, which also matches `post#421` — re-rendering something that did not need it produces the same bytes, and no amount of SQL is going to parse markdown.
- `MarkdownRenderer` builds its converter from an `Environment` rather than the `CommonMarkConverter` shorthand. Same two options, same output; the existing "raw HTML is stripped" and "`javascript:` is refused" tests are what proves it.

### Notes
A reference whose target is gone **unwraps the node, keeping its children**: `[the old post](post#42)` becomes the words, `![Screenshot](media#12)` becomes the alt text. One operation covers both, because an image's alt text *is* its children. The alternative leaves `media#12` in a `src`, which is a broken image on a published page — the visitor pays for the editor's deleted file.

The rewrite works on the parsed document, not on the rendered HTML. A URL is a field of a node there and unwrapping is two calls; on a string both are regular expressions over markup, and the second would have to match a whole `<a>` with whatever is nested inside it.

`LinkTargets` keeps no answers, and that is load-bearing. It memoised them at first and was wrong inside one request: a rename re-renders its referrers in the same request as the rename, and every one of those renders was handed the URL from *before* the slug changed — the rename appeared to do nothing. Found against the running site, not in a test. The dedup that is safe, one picture twice in one document, sits in `InternalLinks`, which knows where a document ends. `LinkTargetsTest::testAnswersAreNeverKept` fails if the cache comes back.

Verified against the running dev site: the same markdown rendered under two different `app.base_url` values, a post renaming propagating into a referrer's cached HTML, and a page rename reaching a post that links to that page's *child*. No migration.

---

## [0.18.0] &ndash; 2026-08-05

The admin is not a theme's to change.

### Changed
- **Admin templates moved to their own view namespace, `dpress_admin:`, registered as not themeable.** A theme overriding one template used to reach *every* template there is — including `admin/layout`, which is what the way in is rendered with. A theme restyling the front end is the whole point of themes; a theme replacing the admin's layout is somebody locked out of their own site. The front end is unchanged and as themeable as ever.
- **The admin icons are plain `.svg` files** in `icons/`, read from disk rather than rendered as templates. They contain no PHP, so calling them templates bought exactly one thing: a theme could replace them.

### Notes
Outside `views/` because they are not templates, and outside `assets/` because nothing serves them over HTTP — they are inlined so the drawing takes the colour of whatever it sits in. `AbstractAdminController::icon()` keeps its per-request memoisation and its fallback to `section.svg`, so a section or row action a plugin adds is still never invisible for want of a drawing.

The menu items screen took its two icons by fetching them itself; they come from the controller now, which is how every list already gets its icons.

Two tests guard the lock: one that no admin file reaches a template through the themeable namespace — a single `dpress:admin/...` left behind would still resolve, and would silently be themeable again — and one that no icon is a template. Verified against the running site with a theme that tried to replace both layouts: the front end was hijacked, the admin was not.

Needs micro 0.19.0.

---

## [0.17.1] &ndash; 2026-08-05

### Fixed
- **The second action of any pair was refused as a forgery.** `Form::process()` mints a fresh CSRF token every time it runs and stores it in the session, so validating one action spends the token printed on the page. That is invisible while every action reloads the page — the new page carries the new token — and fatal for two actions without one in between, which is exactly what uploading a file and then attaching it is. The upload succeeded, the attach was refused, and the message blamed the attach: *"That file could not be attached."* An action that answers with data now hands the new token back, and the browser puts it in the hidden form.

### Notes
It was not only the upload. **Any two panel actions in a row hit it** — hide then detach, attach then attach — because each POST spent the token the last one left. Only the first click after a page load worked.

Rotation is kept rather than removed: a token that leaked out of one response is spent. `AbstractAdminController::answer()` is now the one way an action returns data, so a new one cannot forget the token and leave its second click failing. The browser adopts it from **every** answer including a rejected upload, which validated the token to get as far as being rejected.

---

## [0.17.0] &ndash; 2026-08-05

Uploading from inside the picker, so a file can be added without leaving the editor.

### Added
- **`POST /admin/media/upload/json`** — the same upload as the form, answering with the row instead of a redirect.
- **An upload pane in the media picker**: drop a file on it or choose one, with a progress bar. What lands goes straight to the callback the dialog was opened with, which is the same one a chosen row goes to — so uploading and picking are one path out of the dialog and nothing that opens it has to know which happened.

### Notes
**A separate action rather than a branch inside `upload()`.** That one stays exactly what it was: the way into the library for somebody with no JavaScript, and not a place to grow two behaviours. The dialog cannot follow a redirect anyway — the point of it is that the half-written post behind it is still there afterwards.

**A rejected file is a 200 with an `error`.** Too large, or a type this site does not accept, are ordinary answers to an ordinary request, and `MediaService::upload()` already throws them with a sentence meant for a person. A 500 would claim the server broke and leave the dialog nothing useful to show. Verified: an unaccepted type answers `{"error":"Files of type 'application/octet-stream' are not accepted."}` with status 200, a missing token is a 403, and a missing file says so.

**`XMLHttpRequest`, not `fetch`.** For the one thing it still does that `fetch` cannot: report upload progress. A large photo over a slow connection with no feedback reads as broken, and somebody presses the button again.

The pane is only rendered where `media.create` is held — the endpoint checks it too; the attribute only keeps a useless control off the screen.

---

## [0.16.1] &ndash; 2026-08-05

### Fixed
- **The featured image never showed its thumbnail.** The media field's preview was rendered from a `$media_preview` view variable that **nothing anywhere set**, so a field with an image chosen showed an empty box - the id was there, the Remove button was there, the picture was not. The preview is now part of the field definition, rendered by the form builder from the controller's context.

### Notes
On the field rather than in a view variable, because one variable cannot be the preview of two media fields on the same form - and a category thumbnail next to a featured image is the obvious second one. A media id that no longer resolves shows no preview rather than failing: the field still holds the id, so the editor can see it is set and change it.

---

## [0.16.0] &ndash; 2026-08-05

The attachments panel, in the content editor.

### Added
- **An attachments list under the markdown field**, with the three things an author needs: **attach** a library item, **detach** it, and **hide or show** it — hidden meaning attached but left off the list at the bottom of the published page, which is what an image inside the article wants. There is also a row action that writes the file into the body at the cursor.
- **A button on the markdown toolbar** that picks a file, attaches it **hidden**, and inserts it into the text in one go — because a picture that is in the article should not be listed under it as well.
- Endpoints for all of it, and `Dpress.send()`: the same POST as a row action but over `fetch`, so an editor never reloads and loses what has been typed. Row actions can now be declared `ajax` (post and refresh the list) or `insert` (write into the field), next to the existing `link` and `post`.

### Removed
- **The automatic reconciling of attachments against the markdown**, added in 0.15.0. It fights the author, which is the whole reason the panel exists: detaching a row would re-attach it on the next save, and a hidden flag set by hand would be overwritten. **Removing a file from the text and detaching it are two separate acts, and both are the author's.**

### Notes
`ContentAttachment.hidden` and migration `0008` are unchanged — what moved is who decides. Nothing infers an attachment from the text any more, and nothing infers the text from an attachment.

**The panel writes immediately rather than on Save.** One write model, the same as every other row action in the admin. That is also why it needs a saved post: a new one has no id to attach to, so the panel says so and the buttons are inactive rather than pretending. An abandoned form then cannot leave files attached to nothing either.

The panel sits **outside** the editor's `<form>`. Its controls are buttons; nested inside they would submit the form, and their state would look like something Save is responsible for.

---

## [0.15.0] &ndash; 2026-08-05

An image in the article body is attached to it, and not listed twice.

### Added
- **`ContentAttachment.hidden`** and migration `0008`. An image inside the body is attached — so "is this file still used" has a true answer and a delete says what it affects — but it is already on the page, and listing it again under "Attachments" says the same thing twice.
- **`MediaService::syncInlineAttachments()`**, called after every content save. It reads the body, resolves the storage URLs in it back to library items, and makes the hidden attachments match.
- `MediaService::allAttachmentsOf()` for the places that want the true picture rather than the published one, and `referencedMediaIds()` for anything else that needs to ask what a body points at.

### Notes
**On the link, not on the media.** The same library image can be an inline illustration in one post and a listed download in another, so hidden is a fact about *this use* of it. On `Media` the two uses would fight over one flag.

**Attaching happens on save, not on upload.** A new post has no id until it is saved, so there is nothing to attach to at upload time — and attaching then would never notice the image being deleted from the text again. Reconciling on save makes the markdown the truth for attachments too, which is the rule the rest of the content model already follows, and it means **pasting a URL by hand behaves exactly like using the picker**. There is no hidden state that only a dialog knows how to produce.

**Visible attachments are never touched.** Somebody added those deliberately, to be listed; the article text is not their owner and must not remove them for going unmentioned.

Matched on the **path**, so a full URL and a bare one resolve to the same file — `app.base_url` may be a different host tomorrow, and a stored document must not stop resolving because the site moved. A `-thumb` / `-medium` / `-large` suffix is stripped when it names a preset this installation actually has, so `notes-draft.txt` keeps its name. `usageCount()` counts hidden attachments too: an image inside a body breaks the page exactly as thoroughly as a listed one.

Needs micro-entities 0.7.0 for `addColumnWithAudit()`.

---

## [0.14.4] &ndash; 2026-08-05

### Security
- **A dpress site no longer writes its logs into the document root.** `Logger`'s default directory is the relative `logs`, resolved against the working directory — which for a web request is `public/`. So a site that configured no log directory served its own error log, complete with stack traces, absolute filesystem paths and bound SQL parameters, at `/logs/log_2026-08-05.txt`. `DpressLogger` defaults to `~/logs`, one level above what Apache serves, and both apps register it before the logger is built.

### Notes
The dangerous option should not be the one you get by saying nothing. A site that wants its logs somewhere else still sets `log.dir`; a site that sets nothing is now safe by default.

**Check your own installation.** If `public/logs/` exists, everything in it has been readable by anybody who guessed the filename: delete the directory after upgrading, and treat anything an error mentioned — paths, queries, parameters — as having been public.

Needs micro 0.18.1, where the log directory finally goes through `getFullPath()` so `~/logs` means the site root rather than a folder called `~`.

---

## [0.14.3] &ndash; 2026-08-05

### Fixed
- **The last administrator could be locked out of their own site.** Setting them to Blocked, taking the admin role away in the user editor, or deleting the account all went through unguarded, and `AuthService::login()` refuses anybody who is not active — so the site was left with nobody who could sign in. Recovering needed shell access, which is exactly what the person locked out of their own admin does not necessarily have.

### Notes
The check existed, but only in `UserCommands`: it guarded `dpress user:role -revoke` and nothing else, while the admin UI revoked through `applyRoles()`, blocked through `setStatus()` and deleted through `delete()`. A rule about the state the **site** may end up in belongs in the service every path has to go through, so it moved to `UserService` and now covers all three, in the UI, on the command line, and for anything a plugin calls.

**The rule counts *active* administrators**, not accounts holding the role. With two of them and one already blocked, blocking the other leaves nobody who can sign in while a naive count still says two. By the same reasoning an account that is already blocked is not protected: taking the role from somebody who cannot sign in anyway costs the site nothing.

Pending counts as locked out, the same as blocked — `login()` makes no distinction, so neither does this.

---

## [0.14.2] &ndash; 2026-08-05

### Fixed
- **The uploads `.htaccess` 500'd every file under PHP-FPM.** `php_flag engine off` is a mod_php directive, and Apache does not skip a directive it does not recognise — it refuses the whole directory with `Invalid command`. On any site not running mod_php, every image, every download and every thumbnail was a server error. `Header` had the same problem waiting behind it, since mod_headers is not enabled everywhere either. Every module-specific directive is now inside an `<IfModule>`.

### Added
- **`dpress media:protect`** rewrites `uploads/.htaccess`. It is written once at install and left alone afterwards, so an installation that got the old one had no way back out — and could not be fixed by re-running `install` either.

### Notes
Nothing is lost when `php_flag` is skipped. The `<FilesMatch>` rule below it is the actual lock and does not depend on any module: those files are not served at all, so there is nothing left to interpret. Verified against the running Apache — an uploaded `.php` answers 403 and its contents never execute, while images and SVGs serve normally with the CSP header intact.

---

## [0.14.1] &ndash; 2026-08-05

### Fixed
- **The editor's Status select did nothing.** Setting a post to Published and saving said "Saved." and left it a draft. `ContentService::update()` ignores `status` on purpose — becoming visible sets `published_at` and is what a feed, a cache or a plugin listens for, so it belongs to `publish()` / `unpublish()` — but the editor handed `status` to `update()` anyway and it was dropped on the floor. The editor now makes the transition through the same two service methods the row actions use, so it is announced exactly once however it was asked for.
- **Creating content ignored the publish permission.** `ContentService::create()` *does* honour the status it is given, and nothing checked whether the person may publish before passing it on — so the same select that did nothing on edit published on create. It is checked now, on both paths.
- **The Status select is only rendered for somebody who may publish** that kind of content. The stock `editor` role holds `post.publish` but not `page.publish`, so this was not hypothetical: that role got a select on the page editor that the server then ignored.

### Notes
The three are one bug seen from three sides: `status` was travelling with the ordinary fields, through a method that honours it and a method that ignores it, with nobody asking a permission question on the way. It travels on its own now, through `applyStatus()`, which asks both questions in one place — and a status that is neither `draft` nor `published` is not a third state to move to.

Saving a published post without touching the select no longer re-publishes it. It never visibly did, but it would have moved `published_at` and announced the post again on every corrected typo.

---

## [0.14.0] &ndash; 2026-08-05

Guessing a password now costs something.

### Added
- **Rate limiting on the way in.** `RateLimiter` counts attempts in a sliding window and refuses once a key has had its allowance. Three scopes: logging in (5 per account, 20 per address, in 15 minutes), asking for a password reset (3 per account, 10 per address, in an hour) and submitting a reset token (10 per token, 30 per address, in an hour). Every number is overridable — `dpress.rate_limit.<scope>.account`, `.address`, `.window` — and `dpress.rate_limit.enabled = false` turns the whole thing off.
- **`auth_attempt` table** and migration `0007`. A row per attempt rather than a counter per key, because a counter cannot answer "how many in the last fifteen minutes" without also storing when it was last reset. Not audited, and pruned past the longest window: it is a working set that expires, not a record of anything.

### Notes
**Two limits, always.** A per account limit stops one account being hammered; on its own it hands anybody a way to lock a person out by failing on their behalf. A per address limit stops one password being sprayed across every account; on its own it does nothing about a botnet with a thousand addresses and one target. Neither is optional and neither is sufficient.

**A sliding window, not a lockout.** Once the oldest attempt in the window expires there is room for another. A fixed lockout with a timer is a state somebody else can keep an account in indefinitely, one failure at a time.

**Attempts are counted for addresses that have no account here.** Not counting them would make the limit itself a way of asking who has an account, and guessing addresses is how a spray attack starts. For the same reason the key is stored as a sha256 digest: the set of addresses typed into a login form is exactly the set this site has no business writing down.

**A success clears the account, never the address.** Otherwise anybody holding one valid account could wipe their own address count between guesses at everybody else's.

**The reset form still answers "check your inbox" when it is over the limit.** An error there would tell anybody willing to try that somebody has been asking about that address, and the endpoint exists precisely so that it says nothing about who has an account. Nothing is sent, and the mailbox stops being something a stranger can fill.

**This needs micro 0.18.0**, where `Request::ip()` stopped believing `X-Forwarded-For` from anybody who is not a configured proxy. A limit keyed on an address the client can choose is decoration. **If the site is behind a proxy, set `request.trusted_proxies`** or every visitor shares one address and one allowance.

Registration is not limited. It is one call to the same limiter if a site wants it, but `registration_open` is false by default and a flood of pending accounts is a nuisance rather than a way in.

---

## [0.13.0] &ndash; 2026-08-05

The admin moves between screens without reloading itself, and a list screen costs one request instead of two.

### Added
- **Partial navigation.** A link from one admin screen to another fetches the same URL with `?ajax=1`, which answers with that screen's `<main>` element and nothing else, and the browser puts it where the old one was. The header, the navigation, the stylesheet and the script are the same on every screen; fetching all of them again was throwing away what the browser already had. Back and forward work, the tab's title follows, and the current navigation item is re-marked from a `data-section` the fragment carries.
- **`AbstractAdminController::LAYOUT_PARTIAL`**, a real template - `views/admin/main.phtml` - that the *full* layout also fetches. There is one definition of what `<main>` is, so a partial can never contain something a whole page would not have.
- **`firstPage` on a list configuration.** The screen renders the first page of rows into the list rather than making the browser come back for them, so a list screen is one request on a full load and on a partial one alike, and the table arrives filled instead of flashing empty. `AbstractAdminController::firstPageContext()` builds it, taking the sort from the same configuration the browser is about to be primed with - anything actually in the URL, a filter or a sort somebody linked to, still wins.

### Changed
- **The hidden CSRF action form moved inside `<main>`.** Its token is generated on every render and stored in the session, so a form left outside the swapped part would keep the token of a screen that has since been replaced and every row action after the first partial load would be refused as a forgery.
- **A list is configured through `data-list` rather than an inline `<script>`.** Inserted HTML never runs its scripts, and this is how every other piece of `admin.js` already finds its work: `Dpress.init()` binds whatever it has not bound yet, on the first page and after every navigation.
- The layout's unused `script` block is gone rather than left as a trap that silently swallows its contents on a partial load.
- Every admin template takes its layout from `$admin_layout` instead of naming one. A test fails if a new one names its own, because that would answer a partial request with an entire document.

### Notes
**Anything unexpected is a real navigation.** An expired session, a deleted row, a screen that is not a fragment: the browser is handed the URL and renders it properly. The same goes for deciding which links to catch at all - with rewriting off every screen shares one path, so the server says how routes are written, and being wrong either way costs a partial load and nothing more.

**The fragment is HTML, not JSON and not headers.** What the chrome cannot work out for itself rides on the element as `data-title` and `data-section`. A title with an accent in it survives that; a header would mangle it. And `?ajax=1` in a browser shows exactly what the browser will be given.

**The seed is a head start, not a second source of truth.** The first sort, filter or page change goes to the endpoint like any other. Both sides read the same `ListRequest`, and every seeded page on the dev site is byte-identical to what its endpoint answers.

---

## [0.12.1] &ndash; 2026-08-04

### Changed
- **The admin wears dpress's own logo, always.** It ships in `assets/logo.svg` and `AssetController` serves it with the version in the URL, so it is cached forever like the rest of the admin's assets - and it is safe for exactly the reason an uploaded SVG is not: it is a file this package ships, not one somebody sent us. `site_logo` stays what it was, the site's own mark for the site's own pages, and the admin no longer reads it.
- The site's name sits next to that logo. "Which site am I in" is a question anybody running two of them has, and the header used to answer it.
- The tab icon in the admin is still `site_icon`, because that is the tab an editor keeps open next to the site itself.

---

## [0.12.0] &ndash; 2026-08-04

A site can have a logo and an icon.

### Added
- **`site_logo` and `site_icon` settings.** The logo replaces the site's name in both headers - the admin's and the front end's - and the name becomes its `alt`, because a site with both would say the same thing twice on every screen. The icon becomes `rel="icon"` and `apple-touch-icon`. Neither is set by default, and with neither set nothing changes: the name renders as it did.
- Both are edited on the Settings screen and are audited like every other setting, so "who changed the logo" is answerable.

### Notes
**They store a path, not a URL.** `/static/logo.svg` is resolved against `app.base_url` at render time, so the value survives the site moving out of a subfolder onto a domain of its own - the move that would otherwise silently break every stored absolute URL. Anything that already carries a scheme is left alone, so a logo on a CDN or one inlined as a `data:` URI still works.

**They are settings, not media items.** The library is content; a header logo is chrome. It has to render before anything has been uploaded, on pages that show no content at all, and deleting a picture from the library must not be able to take the header down with it.

---

## [0.11.3] &ndash; 2026-08-04

More admin polish: the row actions are icons.

### Changed
- **Edit, Publish, Move back to draft, History, Delete, Rename and Restore are icons now**, with the name in `title` *and* `aria-label`. Four words per row is a paragraph nobody reads, and the actions column grew with every action a plugin added. Publish is an eye and unpublish is the same eye crossed out, so the pair reads as one state and its opposite; rename gets the text cursor rather than a second pencil, because a menu row carries both and two pencils would say they are the same kind of thing.
- **"Items" on a menu is called "Edit"** and gets the same pencil as everywhere else. A menu's items are what there is to edit about it, and the first action of every other list is Edit.
- `AbstractAdminController::icon()` renders `views/admin/icon-<name>.svg.phtml` and is what both the navigation and the row actions go through, so **`icon` means markup everywhere it appears** — including the picker's `Choose`, which no longer sets it and simply falls back to its title. Missing icons fall back to a generic mark, so a row action a plugin adds is never invisible for want of a drawing.
- The menu items screen is a plain table rather than a dynamic list, and now uses the same two icon files, so it does not end up the one screen with words on it.

### Fixed
- **A row action rendered as an icon had no accessible name.** `title` is not one that can be relied on: it is a tooltip, and whether a screen reader announces it is a setting. The list sets `aria-label` from the title whenever an action has an icon, and the JS test asserts it — along with the escaping on the fallback, since `icon` is `innerHTML` and a title is not.

---

## [0.11.2] &ndash; 2026-08-04

Admin polish.

### Changed
- **The sections moved to a sidebar on the left, with an icon each.** A horizontal bar has to stay short or it scrolls, which is what puts everything a plugin adds out of sight; a vertical list grows downwards for free. The icons are inline SVG rather than `<img>`, so each one takes the colour of the link it sits in — including the inverted one marking the current section — and a font of glyphs that has to load before the nav is readable is not worth it for nine shapes.
- A section names its icon in `navigation()` (`views/admin/icon-<name>.svg.phtml`), separately from the key that marks it current. A section a plugin adds can point at an icon that already exists, and anything the admin cannot find falls back to a generic mark rather than a gap.
- Below 1000px the labels go and the sidebar is an icon rail; below 720px it lies down above the content. **No hamburger**: every section stays one click away, which is the whole point of a menu with nine items in it.
- **Corners are 3px everywhere**, from `--radius`. One small value rather than one per component, so a panel, a button, an input and a badge read as the same material. The status badges were pills and are not any more.
- **The admin is 1280px wide at most**, from `--width`. The dark bar still reaches both window edges, because a band that stops short of them looks like a card that failed to load; everything inside it and below it stops at 1280.

### Fixed
- `composer.json` requires `ext-dom`, `ext-libxml` and `ext-gd` explicitly. All three were already needed — the sanitiser cannot parse without the first two and derivatives cannot be generated without the third — and a missing one should be a message from Composer rather than a fatal on the first upload.

---

## [0.11.1] &ndash; 2026-08-04

### Fixed
- **Saving a post or a page from the browser was a fatal error.** `Content::$parent_id` and `$featured_media_id` are typed `?int`, and both are filled from a control whose "nothing chosen" value is the empty string — assigning that is a `TypeError`. `ContentService` coerces a nullable foreign key now, because it is the one place that knows the column is an id and a service that fatals on ordinary form input is a trap for the next caller too.
- `ContentAdminController` no longer hands raw form values to the service. It names the columns it passes on, so the CSRF token, the tag string, the category boxes and whatever a plugin adds cannot reach the entity by accident — and a field the form does not have stays absent, because `update()` reads absent as "leave it alone".

**Every automated test passed while this was broken**, which is the part worth remembering: the curl checks sent the fields they cared about and left the empty ones out entirely, so `?? null` covered for them. A browser sends every field in the form, empty ones included. The new tests use the values a form actually posts.

---

## [0.11.0] &ndash; 2026-08-04

Phase 6 begins: SVG uploads are sanitised.

### Security
- **An uploaded SVG is sanitised before it is stored.** An SVG is a document, not a picture: it can carry `<script>`, event handlers, `<foreignObject>` with arbitrary HTML in it, references to other sites, and XML entities that expand until the parser dies. `MediaService::store()` now runs the bytes through `SvgSanitizerInterface` **before** the file is moved into place, so what lands on disk is already clean and there is no window in which the original is reachable through the web server.
- **Every absolute reference is stripped, not just the ones in a CSS `url()`.** The library catches the latter; a plain `<image href="http://elsewhere/pixel.png">` went straight through it. Used via `<img src>` a browser will not fetch that — but the file is also reachable at its own address, and there it is a tracking pixel firing from this origin. A stored drawing should be self-contained, so the rule is the blunt one. `data:image/png` and friends stay, because an inert raster is worth embedding; `data:text/html` does not.
- A file that cannot be parsed as SVG once the executable parts are gone is **refused**, rather than stored empty. That is what happens to an entity attack: the doctype is stripped, the entities no longer resolve, and what is left is not a document.

### Added
- `SvgSanitizerInterface`, with `SvgSanitizer` over `rhukster/dom-sanitizer` bound to it. MIT, which is why it is that one — `enshrined/svg-sanitize` is better known and GPL-2.0-or-later, so it cannot ship inside an MIT library, though a site can bind it itself. **Rebinding the interface to something that returns its input is how you turn sanitising off**, and it should look exactly that deliberate; there is no config flag for it.
- `dpress media:sanitize [-id 1] [-confirm]` for a library that predates the sanitiser. **The only thing in the CMS that rewrites a stored file** — write-once exists so a historical revision keeps showing the image it showed, and here the point is precisely that what a file used to contain must stop being served. It reports by default and needs `-confirm` to write.
- `SvgSanitizerInterface::isClean()`, and `MediaService::sanitizeStored()` / `wouldSanitize()`.

### Changed
- The upload screen and `media:import` no longer warn that SVGs are unsanitised, because they no longer are. The uploads `.htaccess` still sends a strict CSP for `.svg`: that is the second lock, for anything predating the sanitiser, not the mitigation.

### Notes
`isClean()` asks whether an element or an attribute **would be removed**, counted rather than compared. Sanitising reserialises the document, so an untouched file comes back with different whitespace and attribute order — a byte comparison reported every SVG in the library as dirty, and a report that flags everything is one nobody reads. The dry run and `-confirm` go through the same question, so they cannot disagree about what needs rewriting.

---

## [0.10.1] &ndash; 2026-08-04

### Fixed
- **Every admin list threw `this.refresh is not a function`.** `DynamicList` called `refresh()` at the top of its constructor, but the methods are function *expressions* assigned to `this` further down — none of them exist until the constructor has run past them. The list now builds and fetches as the last thing it does.

### Added
- `assets/dynamic-list.test.js` — thirteen tests over a stub DOM, run with `node assets/dynamic-list.test.js`. No dependency, no build step. The PHP suite covers what the server sends and nothing covered what the browser does with it, which is how a constructor that could not run got released.

**The version is what busts the asset cache.** `AssetController` serves with `immutable, max-age=31536000`, so a browser that loaded the broken file keeps it until the URL changes — which is the whole reason this is a version bump rather than an edit in place.

---

## [0.10.0] &ndash; 2026-08-04

Phase 5: the admin.

### Added
- **The admin UI.** Nine screens behind `/admin` — a dashboard, posts, pages, the media library, categories and tags, menus and their items, users, roles with a generated permission editor, and settings. Each is two actions: one that renders the page, and, where there is a list, one that answers with JSON.
- **`dynamic-list.js`** — the lists render themselves in the browser and ask the server again on every sort, filter and page change. Modelled on `dynart-micro-js/dynamic-list.js`, rewritten with no jQuery, no build step and no globals from a surrounding application. A **column view escapes by default**: a post title is whatever somebody typed, and returning it raw would put one editor's markup into every other editor's browser. `DynamicListColumnView.html` is the opt out, spelled out at the call site.
- **A list screen is a filter form, a container and one JSON object** — no per-screen JavaScript. `Dpress.list()` takes column views by *name* and row actions as `link` or `post`, because none of it survives being JSON. A screen that genuinely needs a callback still constructs `DynamicList` itself.
- `ListRequest` — turns `sort` / `order` / `offset` / `max` into a query context. **The sort column has to be in a whitelist the calling screen passes in**, because `Query::addOrderBy()` puts the name into the SQL. The page size is clamped rather than rejected, so a hand-written `max=100000` gets a page instead of the whole table.
- `AdminForms` — ten form builders plus `admin_action`, all through `FormFactory`, so a plugin can add a field to any admin screen and it renders with no template change.
- **`DpressForm` renders its own field types** — `markdown`, `media`, `checkboxes`, `permissions` — and falls through to the framework's partial for everything else.
- `AssetController` serves the admin's JS and CSS out of the package, so installing the package installs the admin. The URL carries the version, so the answer can be cached forever.
- `MediaView::rowUrl()` / `rowTag()`, `MenuService::itemRows()`, `TaxonomyService::countCategories()` / `countTags()`.

### Changed
- **Deletes and publishes are POSTs, not links.** A link that changes something can be followed by a prefetcher, a crawler or an `<img>` on another site. Every page renders one hidden form carrying a CSRF token, and a row action points it at the action and submits it.
- The core list queries honour `order_by` / `order_dir` / `offset` / `max` from their context. The name is checked against `^[a-z0-9_]+$` here as well as by `ListRequest` — this is the point where it stops being data and becomes SQL.

### Fixed
- **`ContentService::delete()` could not delete anything that had a tag or a category.** The relation tables carry a foreign key and deliberately no `ON DELETE CASCADE`, so the row was refused by the database. The links now go first, through `TaxonomyService` and `MediaService`, which is also what keeps "which categories did this post have when it was deleted" in the audit rather than losing it inside the database.
- **`MediaService::upload()` called `UploadedFile::tempName()`, which does not exist.** No HTTP upload had ever run — only `importFile()`, from the CLI and the seed.

---

## [0.9.0] &ndash; 2026-08-04

Phase 4: presentation — page routing, menus, settings and themes.

### Added
- **Pages at their own paths.** `PageController` takes the catch-all route, so `/about/contact` works. Because slugs are globally unique the last segment finds the page on its own, but the ancestors are still **checked**: a path that resolves to a real page by a route it does not live at gets a **301 to the canonical one**, so the same content cannot answer at unlimited URLs.
- **`Setting`** — audited, keyed by name, so its mirror is a per-setting timeline needing no replay. `SettingService` loads the table once per request and falls back to `dpress.ini`, so a fresh install works before anything is saved and an operator can still pin a value in the config.
- **`Menu` and `MenuItem`** — a menu is assigned to a *place* declared by the theme; items nest, and each stores **what it points at** rather than a URL, so renaming a page moves its menu entry with it. `MenuService::tree()` resolves targets at render time and **leaves out** an item whose target is gone: a menu entry that goes nowhere is worse than no entry.
- **`ThemeService`** — a theme is a folder under `themes/` with a `theme.ini`; dropping one in installs it. The active theme is a **setting**, not a config value, so switching it is a runtime action and is audited like any other setting. A setting naming a theme that is not installed falls back to the built-in templates rather than fataling on every page.
- **CLI** — `theme:list`, `theme:set`, `menu:list`, `setting:list`, `setting:set`.
- Permissions for `menu.*` and `theme.*`, a `page.phtml` with breadcrumbs and child listing, and a `menu.phtml` a theme can override.

### Changed
- `AbstractController` reads the site name and registration flag from `SettingService` rather than the config, so an editor can change them while the site runs.
- `RequestInterface` and `RouterInterface` moved into the shared registration — the CLI needs them too, because a menu item stores a target rather than a URL and listing a menu has to build one.

### Notes
- **One menu per place.** Assigning a menu to a place moves any other menu out of it, rather than silently rendering only the first.
- Menus are deliberately **not audited** (plan §4.4) — a menu editor rewrites the tree wholesale, so the history would record churn rather than meaning. Settings *are*.
- Requires dynart/micro 0.15.0 for catch-all routes and the redirect status code.

---

## [0.8.0] &ndash; 2026-08-04

Phase 3: taxonomy and the media library.

### Added
- **Taxonomy** — `Category` (hierarchical, with a thumbnail) and `Tag` (flat), plus the audited `ContentCategory` and `ContentTag` join tables. `TaxonomyService` with `setTags()` / `setCategories()` emitting one event per actual change, and `findOrCreateTag()` so an editor can type words rather than identifiers.
- **Media library** — `Media` as a central, audited library that content *references*; `ContentAttachment` is a link, never a copy, so one image can be a featured image on one post and an attachment on another without being stored twice.
- **`MediaTypes`** — an allowlist keyed by the mime type **sniffed from the file's own bytes**. A `.jpg` extension on an executable is refused, because a blocklist is a promise to have thought of every dangerous extension.
- **`MediaStorage`** — write-once paths: `2026/08/my-photo-a1b2c3.jpg`, the slug of the original name plus a **random** suffix. It also writes the `.htaccess` that stops the uploads folder executing anything and sends a strict CSP for `.svg`.
- **`ImageProcessor`** — GD behind its own class, with `thumb` / `medium` / `large` presets from config. Transparency is preserved, and an image smaller than the preset is copied rather than scaled up.
- **Lazy derivatives** — a template points at `…-thumb.jpg`; if the file is there Apache serves it and PHP never runs. If it is not, the existing `!-f` rewrite sends the request to `MediaController`, which generates it, writes it and serves it. Exactly one visitor per size pays.
- **`MediaView`** — `url()`, `tag()` and `icon()`. Non-images render as an inline SVG icon per category, stored as `icon-<category>.svg.phtml` so a theme can replace one like any other template.
- **Front end** — tags, categories, the featured image and attachments on a post; `/tag/<slug>` and `/category/<slug>` archives.
- **CLI** — `media:import`, `media:list`, `media:delete`, `media:purge`, `media:regenerate`, `taxonomy:list`.
- `Content.featured_media_id` gains its foreign key, and permissions for `category.*`, `tag.*` and `media.*`.

### Fixed
- `tag_cloud` selected `id` and `slug` unqualified while joining `content`, which has both — MariaDB rejected it as ambiguous. The fields are qualified now.

### Notes
- **SVG uploads are allowed and not yet sanitised**, deliberately (plan §11.5). The CLI prints a warning, and the uploads `.htaccess` sends `Content-Security-Policy: default-src 'none'` for `.svg` — an SVG used through `<img src>` is a non-scripted context regardless, so the remaining hole is somebody navigating straight to the file, which the header closes.
- **Deleting media marks `deleted_at`; the file stays.** `media:purge` is the only thing that removes bytes, and it refuses to run without `-confirm`, saying how many items reference it and that old revisions will break.
- Derivatives are a cache, so `media:regenerate` only deletes them — the next request rebuilds what it needs.
- The migrations were reordered so `Media` is created before `Content`: a `CREATE TABLE` can only reference a table that already exists.
- Requires dynart/micro-entities 0.6.0.

---

## [0.7.0] &ndash; 2026-08-04

### Changed
- **Every entity declares its own table name** with `#[Table(name: 'user_role')]`, so the tables are `dp_user_role` and `dp_role_permission` rather than `dp_userrole` and `dp_rolepermission`. Written by hand rather than derived from CamelCase: a guess eventually disagrees with what somebody wanted, and it does so silently.
- `CoreQueries` builds join conditions from `EntityManager::safeTableName()` instead of a `#ClassName` token

### Notes
- **No rename migration.** Before 1.0 the development database is rebuilt rather than migrated — see `database/README.md` in the app. Renaming the migration history table cannot be done by a migration anyway, since the runner reads that table to find out what has run.
- Requires dynart/micro-entities 0.5.0, where the `#ClassName` substitution learned about the name attribute.

---

## [0.6.0] &ndash; 2026-08-04

Phase 2: the content model, the markdown pipeline and the revision history.

### Added
- **`Content`** — one audited table with a `type` column for posts and pages, a globally unique slug, and a `(type, status, published_at)` index for the main listing
- **`MarkdownRenderer`** — CommonMark, plus the lead/body split. The rule is the **first line consisting solely of `---` that is not the first line of the document**: at offset 0 it would be opening YAML front matter, and a document that starts with a separator would get an empty lead. A document with no separator is all lead and no body.
- **`Slugger`** — folds accented characters to their ASCII base rather than dropping them, so a Hungarian title gives a readable slug instead of a row of hyphens, and appends `-2`, `-3` until the slug is free
- **`ContentService`** — create, update, publish, unpublish, delete, with full event coverage. Every change emits the generic `content:*` **and** the type alias (`post:created`, `page:created`), so a plugin can subscribe narrowly without inspecting the type.
- **`ContentHistoryService`** — reads the `_aud` mirror back: revisions with author and timestamp, a single revision, a field-level diff, `asOf()` for a point in time, and a recent-changes list
- **Queries** — `content_list`, `content_by_slug`, `content_children`, `content_archive`, all through `QueryFactory`
- **Permissions** — `post.*` and `page.*` per type, plus `content.history`; `Permissions::forContent()` resolves the pair from a row's type
- **CLI** — `content:create`, `content:list`, `content:publish`, `content:delete`, `content:history`, `content:rerender`
- **Front end** — the published posts on the home page and a single post at `/post/<slug>`. Somebody who may edit posts can preview a draft; a visitor gets a 404.
- `Cli\AbstractCommands` with a `param()` helper

### Fixed
- **Optional CLI parameters never reached their default.** `CliCommands::matchCurrent()` pre-fills every *declared* parameter with an empty string, so `$params['role'] ?? Role::NAME_ADMIN` always got `''` — `user:create` without `-role` failed with "There is no role named ''". `AbstractCommands::param()` treats an empty parameter as absent, which for a CLI is the same thing.

### Notes
- **`content_by_slug` defaults `published_only` to true.** The filter is on unless the caller asks for drafts, so a forgotten flag cannot leak unpublished work.
- Deleting a page **re-parents its children** rather than cascading. A cascade would delete a whole subtree because somebody removed one page in the middle, and it would happen inside the database where nothing is audited.
- The rendered HTML is a cache of the markdown, so it is only ever written through `ContentService::renderInto()`; `content:rerender` rebuilds it after a rendering change.
- `prefer-stable` added to the composer files — without it `minimum-stability: dev` pulled `league/commonmark` as `dev-main`.

---

## [0.5.0] &ndash; 2026-08-04

Phase 1 complete: the HTTP layer. Login, logout, registration, password recovery and the profile, all verified end to end against Apache and MariaDB.

### Added
- **`DpressWebApp`** — the middleware order that makes cookie-based login work: `JwtCookieReader` (40) lifts the access token out of its cookie, `TokenRefresher` (45) renews it from the refresh cookie when it has aged out, `JwtValidator` (50) decodes it.
- **`TokenRefresher`** — renews an expired access token before the validator sees the request. Without it a 15-minute access TTL means a 401 every 15 minutes for somebody who never logged out. It decodes nothing: the access cookie is set to expire slightly before its token, so an aged-out session arrives with no `Authorization` header at all, which is exactly the case it handles.
- **`AuthCookies`** — HttpOnly, SameSite=Lax cookies for both tokens; `jwt.cookie_secure` turns on `secure` in production
- **Controllers** — `AuthController` (login, logout, register, forgot-password, reset-password), `ProfileController` (`#[Authorize]`, so any logged-in user), `HomeController`, all on `AbstractController`
- **`CoreForms`** — the five identity form builders, and the `EmailValidator` / `MinLengthValidator` / `MatchFieldValidator` they use. `MatchFieldValidator` reads the other field off the form at validation time, which is what `AbstractValidator::setForm()` is for.
- `dpress user:status -email x -status active` — registration creates a *pending* user, so without this there was no way to activate one short of editing the database
- **Views** — a layout and the auth pages, every form rendered through `$form->fetch()` so a plugin-added field appears without touching a template
- `translations/micro/en.ini` — overrides the framework's built-in form messages with wording meant for a visitor rather than a developer

### Changed
- **`FormFactory::add()` and `QueryFactory::add()` register a `[Class, 'method']` builder in the DI container**, the same thing `Migrations::add()` does. The builder is resolved through the container, so without this every caller needed a second `Micro::add()` and found out at runtime.

### Notes
- Refresh tokens **rotate**: refreshing revokes the old one, so a stolen token is usable at most once. A spent token makes `TokenRefresher` clear the cookies and continue anonymously rather than throw — a stale cookie must never lock somebody out.
- `/logout` is POST only, so a link on another page cannot log a visitor out.
- **`Router::currentRoute()` reads the path from a request parameter**, not `REQUEST_URI`, so the rewrite has to pass it: `RewriteRule ^(.*)$ index.php?route=/$1 [QSA,L]`. `public/router.php` does the same for PHP's built-in server.
- Requires dynart/micro 0.13.0, which fixes the two `View` bugs that stopped a form and a layout being combined at all.

---

## [0.4.0] &ndash; 2026-08-04

### Added
- **Mail** — `AbstractMailer` renders, a subclass delivers. A mail is two templates: `<template>.phtml` for the HTML body and an optional `<template>.txt.phtml` for the plain text alternative, both fetched through `ViewInterface` so a **theme overrides either one independently**, exactly the way it overrides a page template. `send($name, $email, $subject, $template, $variables)`; `create()` renders without sending.
- `LogMailer` (the default) writes the mail to the log, so a password-reset flow can be walked through without an SMTP server and the reset URL is there to click. `NativeMailer` sends through PHP `mail()`, `multipart/alternative` when a text body exists.
- `Mail` value object with header-safe address formatting — a non-ASCII display name is base64 encoded, or it arrives as mojibake.
- `mail.mailer` config picks the mailer by short name (`log`, `native`) or by class name, so an application plugs in PHPMailer or Symfony Mailer with a subclass and one line.
- `mail:before_send` / `mail:sent` / `mail:failed` events; the before event carries the rendered mail and fires before the transport sees it, so a subscriber can still change it
- Default templates: `views/mail/layout.phtml`, `views/mail/password-reset.phtml` and its `.txt.phtml`
- `dpress mail:test -email x [-render]` — renders and sends a test mail, and reports which mailer is actually in use
- `Dpress::viewsPath()` / `translationsPath()` — the package ships its own views and translations, which live wherever Composer put them rather than under the site root, so they cannot use the `~` alias

### Notes
- **In `multipart/alternative` the text part comes first.** A mail client displays the *last* part it can render, so HTML has to be the later one.
- Requires dynart/micro 0.12.0 for `View::exists()` — deciding whether the optional text template is present by catching the exception from `fetch()` would also swallow a `MicroException` thrown from inside a template that does exist.

---

## [0.3.0] &ndash; 2026-08-04

Phase 1, part one: the identity domain and its CLI. The HTTP flows (login, registration, profile) come next.

### Added
- **Identity entities** — `User`, `Role`, `UserRole`, `RolePermission`, `RefreshToken`, `UserToken`. Password resets and email verifications share one `UserToken` table with a `type` column rather than two tables that would have to be kept in step.
- **`Permissions`** — the registry of permission strings. A plugin calling `add('myplugin.do_thing')` shows up in the role editor without a migration or a lookup table.
- **`DpressUser`** — the `JwtUserInterface` the framework's `#[Authorize]` checks against. An admin holds every permission implicitly, so a permission invented later by a plugin needs no retroactive grant.
- **`UserService`** — create, register, update, delete, password and role changes, each emitting before/after events
- **`RoleService`** — roles and their permissions, with `setPermissions()` emitting one event per actual change
- **`AuthService`** — login, logout, refresh and password reset. Issues a short-lived access token carrying the user's roles and permissions, plus a refresh token stored hashed. Refreshing revokes the old token and issues a new one, so a stolen token is usable at most once.
- **`PasswordHasher`** — passwords through `password_hash()`, and single-use tokens hashed with sha256 (they are long random values, so there is nothing to salt and a lookup has to be one indexed query)
- **`CoreQueries`** — the CMS query builders, all registered through `QueryFactory`
- **CLI** — `user:create`, `user:password`, `user:list`, `user:role`, `role:list`. `user:create` and `user:password` generate a password when none is given, so a site can be bootstrapped and a locked-out administrator can get back in without touching the database.
- `Migration\CreateIdentityTables` — the tables plus the three default roles

### Notes
- **The audited relation tables carry no `ON DELETE CASCADE`.** A cascade happens inside the database, so no entity event fires and no audit row is written — the history would show a role grant simply gone. `UserService` and `RoleService` delete those rows through the entity manager before removing the parent.
- The admin role is seeded **unremovable and with no explicit permissions**, because it holds all of them implicitly. `user:role -revoke` refuses to remove the last administrator.
- Login refuses a blocked or pending account with the same message as a wrong password, and `createPasswordResetToken()` returns null for an unknown address rather than throwing, so neither turns into a way of finding out who has an account.
- Requires dynart/micro 0.11.0 (`JwtCookieReader`, `Response` cookies) and dynart/micro-entities 0.4.1.

---

## [0.2.0] &ndash; 2026-08-04

The two factories that make the CMS extensible. Both are in Phase 0 rather than alongside the plugin system, because a query built with `new Query(...)` inside a service, or a form rendered as hand-written HTML, is permanently closed to extension — neither can be retrofitted without rewriting every screen.

### Added
- **`QueryFactory`** — every query is built by name and handed to its subscribers before it is returned, so a plugin can attach conditions, joins and ordering to a query it did not write. Emits a scoped `query.<name>:created` and a generic `query:created`.
- **`FormFactory`** and **`DpressForm`** — the same for forms. The factory emits `form.<name>:created` and the generic `form:created`; `DpressForm` emits `form.<name>:validated` from the framework's `afterValidate()` hook, and its `handle()` wraps the controller's work in `form.<name>:before_process` / `:after_process`.
- `DpressServices::registerWeb()` — the request, the session and the form factory, kept out of `register()` so a CLI command never touches `Session`
- `DpressException`

### Notes
- Both factories emit a scoped **and** a generic event on purpose: `EventService` matches names exactly with no wildcards, so a generic-only event would wake every subscriber on every form and every query.
- **Plugins can narrow a query but never widen it.** `Query` has no `removeCondition()`, conditions are appended, and the query builder joins them with `AND` — so a subscriber cannot strip the published-status filter off a public listing. This holds only if the registered builder adds its own security-critical conditions rather than leaving them to the caller.
- Form and query names are snake_case with no dots, so they slot cleanly into one event-namespace segment.
- Requires dynart/micro-entities 0.4.0 for `Query::nextParamName()` and the bound-variable collision check.

---

## [0.1.0] &ndash; 2026-08-04

The package skeleton and the command line tool. No content model yet.

### Added
- `dpress` command line tool with a bash launcher, a batch launcher for Windows, and a single PHP entry point both delegate to
- Config discovery: `-config <path>`, otherwise the directory tree is walked upward looking for a `dpress.ini`
- Commands: `install`, `upgrade`, `migrate:status`, `version`, `help`
- `DpressCliApp` with the command table as data, so `dpress help` and the config-requirement check read from one source
- `DpressServices` — the DI registrations and core migration list shared by every kind of dpress application
- `SchemaService` — install / upgrade / status, between the migration runner and whatever drives it
- `Migration\CreateRevisionTable` — the first migration, creating the table the auditing depends on
- `Dpress` — version and the shared constants

### Notes
- `install` is idempotent: it applies whatever is pending rather than refusing when the migration history table already exists, which is the state a failed migration leaves behind
- Requires dynart/micro 0.10.0 and dynart/micro-entities 0.3.1
