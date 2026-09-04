# Blocks

A **block** is something in a place beside the content: a tag cloud, a category list, a piece of
markdown you wrote. A site with none renders exactly what it rendered before — no sidebar column,
no extra markup, no extra query.

## Places

A **place** is a spot in the layout, and it is the same word menus use. A theme declares what it
has:

```ini
places[] = main
places[] = sidebar
```

The built-in templates declare `main` (the header) and `sidebar` (beside the content). Whatever is
assigned to a place renders there — a menu, blocks, or both — so there is one vocabulary and one
list, not `places` for menus and `regions` for blocks.

A block in no place, or in one the active theme stopped declaring after a theme switch, is
**invisible rather than broken**: the site does not render it, `/admin/blocks` lists it under *Not
rendered*, and putting it back is one select.

## The kinds that ship

| Type | What it does |
|---|---|
| Tag cloud | The tags in use, sized by how much they are used. `limit` keeps the most used ones. |
| Category list | The categories, nested the way they are nested. |
| Markdown | Whatever you write. |
| Ko-fi button | A link to a Ko-fi page, with their cup on it. |

### Ko-fi

Four boxes: the **page name** - the bit after `ko-fi.com` in the address, and the whole address
is accepted too - the **button text**, a **hex colour**, and an optional line of **description**
above it.

It is a link and an image, and that is the whole of it: no iframe, no script, and nothing
loaded at all on a page that does not have the block. Ko-fi's own widget is an iframe with a
script in it. The one third-party request left is the cup, an image on their CDN.

The colour is validated as six hex digits rather than escaped, because it goes into a `style`
attribute - what has to be impossible is a settings box naming a *declaration*. Anything
unreadable falls back to Ko-fi's own blue, so a mistyped colour looks like Ko-fi rather than
looking broken. **The text on the button turns black or white by itself**, whichever can be
read on the colour chosen: a pale brand colour would otherwise get white text nobody can read,
and nothing would tell the site owner that is what happened.

The page name is validated the same way and for the same reason - it goes into an `href`.
A name that is not one renders **nothing**, rather than a button pointing at `ko-fi.com` and
asking the reader which of several million pages was meant.

### Markdown

**The markdown block is the general one**, and a picture inside a link is a good example of what
that buys:

```markdown
[![Sponsor me](media#14)](https://example.com/sponsor)
```

which is worth reading twice, because it is three of this CMS's rules meeting. The image is
`media#14`, so no path to a file is stored and [moving the site](internal-links.md) cannot break
it. It is markdown, so it is the same thing you write everywhere else, with the same toolbar and
the same media picker. And `{{ video('media#10') }}` works in here too, with nothing added, because
[shortcodes](shortcodes.md) are expanded over the finished page rather than over a piece of
content.

Like a post, **a markdown block is rendered when it is saved**, not when it is shown — which is why
`dpress content:rerender` re-renders blocks as well.

## The screen

`/admin/blocks` is a table per place. Drag the handle to reorder within a place; the select in the
editor moves a block to another place. Adding one asks which kind first, because the fields depend
on the kind and a form that rebuilds itself under you is a form that loses what you typed.

A block can be switched **off** without being deleted. It stays listed, marked, and renders nowhere.

## Writing one

A block type is a registration, exactly like a shortcode or a field type:

```php
$blocks->add('kofi', [
    'title'  => 'Ko-fi button',
    'render' => [KofiBlock::class, 'render'],       // fn(Block, array $settings): string
    'fields' => ['handle' => ['type' => 'text', 'label' => 'Ko-fi name']],
    'prepare' => [KofiBlock::class, 'prepare'],     // optional, runs at save
]);
```

- **`fields`** is a form field list, merged into the block editor. That is what keeps the editor
  from being a template that branches on `type` — the mistake `FormWidgets` exists to have taken
  out of form rendering. A type whose options have to be worked out at the time subscribes to
  `form.admin_block:created` like anything else adding to somebody's form.
- **`prepare`** is the save-time hook: turn what somebody typed into what should be stored. Use it
  for anything expensive, so a page view only prints. `dpress content:rerender` calls it again.
- **`render`** gets the block and its settings and returns HTML. Fetch a template rather than
  building markup in PHP, so a theme can override it.

Everything is a Micro callable resolved when it is needed, so an enabled type that is not on the
page costs nothing.

The settings live in one JSON column rather than a column per type, which is what makes a plugin's
block possible at all: a new kind of block is a registration, never a migration.

## What it costs

A place with something in it is **two queries** — the ids in order, then the rows — plus whatever
the blocks themselves ask for. Measured on the development site at about **1 ms** for a sidebar
holding a tag cloud, a category list and a markdown block. A site with no blocks pays for nothing:
the layout asks the place, the place answers `''`, and no query is made.

## Events

| Event | When |
|---|---|
| `block:saved` | after a block is created or changed |
| `block:deleted` | after one is removed |
| `block:before_render` | with the place and its blocks, before any of them draw |
| `query.block_list:created` | the read path — a plugin can narrow what a place shows, never widen it |
