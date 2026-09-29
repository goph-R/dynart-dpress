# Themes

A theme is a folder under `themes/` with a `theme.ini` in it. Dropping the folder in installs it;
there is no registry and nothing to run. Which one renders is a **setting**, so switching is a
runtime action and an audited one.

```
themes/gopherlab/
  theme.ini                     title, description, version, author, and the places it renders
  assets/style.css              the design, served at /assets/theme/style.css
  assets/inter.woff2
  dpress/layout.phtml           the reading layout - overrides the CMS's
  dpress/layout-home.phtml      the front page's
  dpress/content/list.phtml     an override of any template the CMS ships
  partial/head.phtml            the theme's own, in its own namespace
```

Three of those four things are new in 0.39.0: the second layout, the assets folder, and `partial/`.

---

## 1. Two layouts, and how a theme asks for one

Every front-end template renders through **`$layout`**, a variable, rather than through a name
written into the template. A controller says what kind of page it is rendering and
`AbstractController::layoutFor()` turns that into a template name — if the theme has a file for
it.

| Kind | Where it comes from | Template a theme may write |
|---|---|---|
| `home` | `HomeController::index()` | `dpress/layout-home.phtml` |
| `archive` | a category or a tag listing | `dpress/layout-archive.phtml` |
| `post` | a post, at `/post/<slug>` or `/<slug>` as `post_path` says | `dpress/layout-post.phtml` |
| `page` | any page, at its own path | `dpress/layout-page.phtml` |
| `auth` | log in, register, profile, a message | `dpress/layout-auth.phtml` |
| *(none)* | anything else, a plugin's screens included | — |

**Nothing registers a layout. Having the file is the registration.** The resolution asks the view
whether the template resolves, which — because a theme folder is checked before every namespace
folder — is answered by the theme. So a kind with no file behind it comes back to
`dpress/layout.phtml`, and that is what makes naming five kinds free: a theme that writes one
extra file gets two layouts and pays nothing for the other three.

That is also the answer to *"my category pages read like a post"* — it is the decision not to
write `layout-archive.phtml`.

**The kind reaches the template too, as `$layout_kind`,** which the built-in layout prints as a
class on `<body>`. A theme that wants one layout and two shapes of it writes `body.home { … }` and
never adds a second file. Use a second file when the *markup* differs — a hero, a grid, a column
that is not there — and the body class when only the CSS does.

### What this replaces

Nine templates used to say `dpress:layout` out loud. A theme wanting a second layout had to
override all nine of them to alter one string, which is why nobody would have.

---

## 2. A place only one layout renders is a place that only appears there

This falls out of two layouts and is worth stating on its own, because it is the cheap version of
a feature that looked expensive.

A theme declares its places in `theme.ini` and both editors offer them. Nothing says *where* a
place is — that is the layout's business. So:

```php
// dpress/layout.phtml - the reading layout
<aside class="sidebar"><?= $places->render('sidebar') ?></aside>

// dpress/layout-home.phtml - the front page, which does not mention `sidebar` at all
<?= $places->render('home_top') ?>
```

A block put in `sidebar` shows beside a post and stays off the front page. A block put in
`home_top` appears on the front page and nowhere else. **There is no visibility rule anywhere**,
nothing to configure, and nothing for a block author to get wrong: the layout that does not ask
for a place does not get it.

**A theme that declares `places[]` replaces the built-in list rather than adding to it.** With no
theme active the built-in templates declare `main` (the header), `sidebar` (beside the content)
and `after_content` (under a post and under a page, outside the `</article>`, for whatever is
*about* what was just read — comments above all). A theme wanting any of those has to name it,
`places[] = after_content` included, and render it in its own templates.

A featured strip, a welcome note, a front-page-only newsletter box are all this. What it will not
express is a condition finer than "which layout" — *"this block, but only on posts in the Retro
category"* still wants rules, and does not have them.

---

## 3. Assets

A theme keeps its stylesheet, fonts and pictures in its own `assets/`, and they are served at
`/assets/theme/<file>`:

```php
<link rel="stylesheet" href="<?= esc_attr($theme->url('style.css')) ?>">
<img src="<?= esc_attr($theme->url('hero.png')) ?>" alt="">
```

`$theme` is a `ThemeAssets`, set on every render like `$places` is, so a template looks nothing
up itself. Two more things it answers:

- **`$theme->url('inter.woff2', false)`** leaves the version off, for a file **named** after its
  own contents — a font, above all. A `url()` inside the stylesheet carries no version, because a
  stylesheet cannot know one, so a `<link rel="preload">` built with `?v=` is a different URL
  from the one `@font-face` asks for and the browser downloads the font twice.
- **`$theme->exists('icons.css')`**, for something **optional** — the same rule the route serves
  by, so a template is never told yes about a file that would then 404.

- **Cache-busted by the theme's own version** from `theme.ini`, not by the CMS's — a theme is
  released on its own schedule, and upgrading Dpress should not expire a font nothing touched.
  The answer carries `Cache-Control: public, max-age=31536000, immutable`.
- **The active theme's, and no other.** The theme name is not in the URL. There is one theme
  rendering and a name in the URL would be a way to read out of any folder under `themes/`
  whether the site uses it or not — the same rule a plugin's assets follow.
- **Allowed**: `css js svg png jpg jpeg gif webp avif ico woff woff2 ttf otf` — a typeface and a
  background are not extras for a design. It is `ThemeAssets::TYPES`, and since 0.59.0 a plugin
  may serve exactly the same list: one answer to what a folder dropped into the site may put in
  front of a browser, rather than two.

### Flat, on purpose

One folder, no subfolders — a name is `[A-Za-z0-9_-]+\.[A-Za-z0-9]+` and nothing else. Two things
come out of that and both are worth more than a `fonts/` folder would be. A name that cannot
contain a slash or a dot pair **cannot climb anywhere**, so there is no traversal to reason about
at all. And `url(hero.png)` written inside `style.css` resolves against `/assets/theme/` and
simply works, which it would not if the stylesheet lived a folder deeper than the picture.

### Why not `public/static/`

Because then a theme is two folders in two places: copying one is copying two, uninstalling one
leaves the other behind, and a second theme's `style.css` collides with the first's. A theme
folder is meant to be the whole theme.

---

## 4. `theme:` — the theme's own templates

Two layouts want one header between them. `dpress:` is for **overrides** — put
`dpress/content/single.phtml` in the folder and it replaces the CMS's — and a shared header is not
an override of anything, so calling it `dpress:partial/head` would be a theme claiming a name in
the CMS's namespace for a file the CMS does not have and would not recognise.

So an active theme gets a namespace pointing at its own folder:

```php
<?= $this->fetch('theme:partial/head', ['title' => $title ?? '']) ?>
<?= $this->fetch('theme:partial/chrome', ['part' => 'header']) ?>
```

Registered by `ThemeService::apply()` and only while a theme is active, which is the only time
anything can ask for it.

**Name the variables a partial needs.** A nested `fetch()` sees the view's own variables — `$title`
is not one of them; it is passed per render. And never hand it `get_defined_vars()`: a template
body is `include`d inside `View::fetch()` and shares its scope, so that passes down the path of
the file being included and the nested fetch includes its caller instead, forever.

### One category, tag, page or post with a template of its own (0.89.0)

WordPress's template hierarchy: a screen tries the most particular template first, in the theme's
`dpress/content/` folder, and takes the first one there is.

| Screen | Tried in order |
|---|---|
| A category | `category-<slug>`, `category-<id>`, `category`, `list` |
| A tag | `tag-<slug>`, `tag-<id>`, `tag`, `list` |
| A page | `page-<slug>`, `page-<id>`, `page` |
| A post | `single-<slug>`, `single-<id>`, `single` |
| The front page | `home`, `list` |

So a gallery for one category is `dpress/content/category-photos.phtml`, and every category is
`category.phtml` - with nothing to register. The slug first, as WordPress does it: it is what the
author of a theme reads, and the id is for a slug that is going to change. Only a plain
`[a-z0-9-]` slug is tried, since it becomes part of a file path.

A category's templates get **`$category`** and a tag's **`$tag`** - the entity, with its
`description` and a category's `thumbnail_media_id` - besides what `list` gets.

---

## 5. What a template is given

Set on every render, so a theme's templates never look anything up:

| | |
|---|---|
| `$layout`, `$layout_kind` | which layout, and what kind of page it is |
| `$places` | `render($place)`, `menu($place)`, `blocks($place)` |
| `$theme` | `url($file)` and `exists($file)` for this theme's assets |
| `$site_name`, `$site_description`, `$site_logo`, `$site_icon` | the branding, resolved and ready to print |
| `$main_menu` | the `main` place's menu, already rendered |
| `$current_user`, `$registration_open` | for the header |
| `$title` | the page's own title, per render |
| `$dates` | `format()`, `iso()` and `tag()` for a stored timestamp |
| `$post_path` | `/post/` or `/`, as the `post_path` setting says — `route_url($post_path.$post['slug'])` |

Content templates add their own: `$content`, `$posts`, `$tags`, `$categories`, `$attachments`,
`$mediaView`, and the paging set (`$body_html`, `$page`, `$page_count`, `$show_lead`, `$prev_url`,
`$next_url`, `$page_urls`).

Three of those are what a card-shaped listing needs, and both cost one query for the whole page:

- **`$thumbnails`**, on every listing, keyed by **content** id — `$thumbnails[$post['id']]`, or
  nothing. A row carries `featured_media_id` and not the item, so without this a theme that wants
  a picture on a card has the id and nothing to do with it. A post with no picture and a post
  whose picture was deleted are both simply absent, so `isset()` is the whole check.

  **The presets, and a `srcset` of them** (0.87.0). `thumb` is at most 320×320, `medium` 768 and
  `large` 1600, and **none is cropped** - each keeps the picture's shape, so a card crops with
  `object-fit` to whatever shape it is. `$mediaView->srcset($media, ['thumb', 'medium'])` lists
  the files at their real widths, and with a `sizes` saying how wide the card is drawn the
  browser takes the smallest one that is sharp there:

  ```php
  <?= $mediaView->tag($thumbnails[$post['id']], 'medium', [
      'alt'    => '',
      'srcset' => $mediaView->srcset($thumbnails[$post['id']], ['thumb', 'medium']),
      'sizes'  => '(min-width: 1025px) 120px, 100vw',
  ]) ?>
  ```

  An image `srcset()` cannot describe (an SVG, no stored size) answers '', and `tag()` leaves an
  empty `srcset` or `sizes` off - `src` is the picture then.
- **`$authors`**, on every listing, keyed by content id — a name, or nothing. Same reason as
  `$thumbnails`: a row carries `author_id` and not a name. A single post gets `$author` instead,
  as a plain string. So a byline is
  `<?php if (isset($authors[$post['id']])): ?>by <?= esc_html($authors[$post['id']]) ?><?php endif ?>`.
- **`$featured_posts`**, on the front page only: the posts tagged with whatever `featured_tag` names,
  newest first, at most five. They are **left out of `$posts`**, because pinned at the top and
  repeated four rows down reads as a bug rather than as emphasis. An empty setting, a tag nobody
  has used, or no tag of that name at all all mean the same thing - no strip - so a theme asks
  `if ($featured_posts !== [])` and nothing else.

It is `$featured_posts` and not `$featured` on purpose: a single post's template has had
`$featured` for **its own picture** since there were templates, and one name meaning two things is
a bug a theme author writes once and debugs twice.

---

## 6. Dates

**Never print `substr($row['published_at'], 0, 10)`.** Timestamps are stored UTC, so those ten
characters are the *UTC* day — a post published at half past midnight in Budapest shows the day
before, to everybody. `$dates` reads the site's `timezone` and `date_format` settings:

```php
<?= $dates->tag($post['published_at']) ?>          <!-- <time datetime="...">January 6, 2026</time> -->
<?= esc_html($dates->format($post['published_at'])) ?>   <!-- just the text -->
<?= esc_html($dates->format($post['published_at'], 'Y')) ?>  <!-- a format of the theme's own -->
```

`tag()` is what to reach for: the printed date reads however the site set it, and the `datetime`
attribute still says what it means to a feed, a reader or a search engine. Nothing stored gives
`''` back rather than a date in 1970, so a draft prints nothing.

---

## 7. The preview bar

The editor has a **Preview** button that posts the boxes and renders them through the theme,
saving nothing. So a theme that replaces `content/single.phtml` or `content/page.phtml` is what
a preview looks like, and it has to say so — a page that reads exactly like the live one, with
unsaved words on it, is a good way to publish something by accident.

One line at the top of each, and the CMS draws it:

```php
<?php $this->startBlock('content') ?>
<?= $this->fetch('dpress:content/preview-bar', ['preview' => $preview ?? false]) ?>
```

Unconditional on purpose: the partial renders **nothing at all** unless `$preview` is set, so
there is no flag for a template to get wrong. It carries its own inline styles rather than
asking for a class, because it has to look like itself in a stylesheet it has never met — put a
`content/preview-bar.phtml` in the theme to replace it.

A theme that leaves the line out still gets a preview; it just looks like the live page, and the
only thing saying otherwise is the `/admin/...` address in a new tab.

---

## 8. What a theme cannot do

- **Not the admin.** `dpress_admin:` is registered as not themeable. A theme replacing the admin
  layout is not a restyled page, it is somebody locked out of their own site.
- **No code.** A theme is templates, a manifest and files. Anything that has to *run* is a
  plugin — see [plugins.md](plugins.md).
- **A missing theme is not a broken site.** A setting naming a theme that is not on disk falls
  back to the built-in templates rather than fataling on every page.
