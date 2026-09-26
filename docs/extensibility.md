# Extensibility: what plugins still cannot do, and what comes next

**Status: planning** (after Dpress 0.76.1). **§1 is built in 0.77.0** - see
[plugins.md §5c](plugins.md); the rest is the order after it.

The admin is close to a production release, and what stands between Dpress and "flexible" is
mostly on the plugin side. This page is the list, in the order it is worth doing, with the design
of each at the depth it has been thought through - §2 is the one that needs a design note of its
own before any code.

## Where plugins stand today

A plugin can already contribute, declaratively: services, controllers, entities, migrations,
form field types, blocks, shortcodes, page assets, permissions, view folders, admin scripts and
styles, and settings fields - into a section of their own on the settings screen since 0.75.0.
Through the form events it can add a field to any form the CMS builds, into any section of it.
So a plugin can already **build** an admin screen.

What it cannot do:

1. **Be found in the admin.** The navigation is a fixed list in
   `AbstractAdminController::navigation()`, so a plugin's screen is reachable only by typing its
   address. Nor can it bring an icon: `Dpress::icon()` reads the CMS's own `icons/` folder.
2. **Add a kind of content.** `Content::TYPES` is post and page, and the admin answers any other
   type with a 404. A plugin wanting *recipes* rebuilds the list, the editor, the trash, the
   history, the preview and the public page - everything the two built-in types get for nothing.
3. **Extend a screen it did not make**: a card on the dashboard, a column or a row action on the
   Posts, Pages or Media list.
4. **Add a `dpress` command.** `DpressCliApp::COMMANDS` is a constant, so a plugin whose work
   belongs in a deploy or a cron job - the Docs plugin's `docs:build` - has no way to offer it.
   A `commands()` on the plugin interface, the same shape of change as `adminSections()`, is
   next after §1.

## 1. Admin menu sections from plugins

**Built in 0.77.0.** Small, and it unblocks every plugin that has a screen.

```php
public function adminSections(): array {
    return [
        'recipes' => [
            'label'      => 'Recipes',
            'route'      => '/admin/recipes',
            'permission' => 'recipe.view',
            'icon'       => 'recipes.svg',   // a file in the plugin's folder, or a core icon's name
            'after'      => 'media',         // or 'before' => 'users'; the end of the content group if neither
        ],
    ];
}
```

- A **registry**, `AdminSections`, holds the core's sections and the plugins' alike, the way
  `SettingFields` holds the settings - so `navigation()` reads one list rather than a constant
  and a list of additions, and the core's own sections go through the call a plugin uses.
- **A key a plugin gives cannot replace one that is already there.** A plugin that names its
  section `posts` gets a warning in the log, not the Posts entry.
- **The icon** is a core icon's name, or a `.svg` in the plugin's folder, read the same way the
  core's are - from its `<svg` on - and never from outside that folder.
- **The current section** is the one whose key the screen's controller answers from `section()`,
  as the core's do; a plugin's controller extends `AbstractAdminController` and overrides it.
- A section whose permission the user lacks is not shown, the rule the core's follow.

## 2. Plugin content types

**The step that makes Dpress flexible. A release of its own; a design note before code.**

A declaration, stored in the **one content table** the original plan insists on:

```php
public function contentTypes(): array {
    return [
        'recipe' => [
            'label'        => 'Recipes',
            'singular'     => 'Recipe',
            'icon'         => 'recipes.svg',
            'hierarchical' => false,     // a tree like pages, or a list like posts
            'path'         => 'recipe',  // the public address: /recipe/<slug>
        ],
    ];
}
```

Declaring one would give it, with no screen of the plugin's own:

- a menu entry (§1), a list, an editor, the trash, the history with its Preview and Restore,
  up and down, featured pictures, the Advanced section;
- permissions `recipe.view`, `recipe.create`, `recipe.update`, `recipe.delete`, `recipe.publish`,
  generated and in the role editor by themselves;
- a public page at `/recipe/<slug>`, rendered with `content/single-recipe` where the theme has one
  and `content/single` where it does not;
- `recipe#12` as an internal link, a place in the sitemap, and - where the type says so - the feed.

**Fields of its own** - a recipe's cooking time, its servings - come through the form events the
editor already has, and need one thing that does not exist yet: a **content meta** table, a key
and a value per content row, written in the same save and **kept in the revisions**, so History
restores a recipe's servings along with its text. Without it every plugin makes a table of its
own, the way the Disqus plugin had to for its identifiers.

What the design note has to settle: whether a type may choose to have categories and tags; how
`publicPath()` and the router learn a type's path without a query per request; what happens to
a type's rows when its plugin is switched off (hidden, not deleted - the same answer the trash
gives); and how a theme lists a type (`content_list` with a `type` already works).

## 3. Smaller hooks

Each is an event on something that is a list today, in the pattern the forms and queries follow:

- **Dashboard cards** - so a content type's card, and any plugin's count, appear on their own.
- **List configuration** - columns and row actions on the Posts, Pages, Media and Users lists.
- **The editor's sections** - to open one by default, or order a plugin's before the core's.

## Polish, alongside

- **Full-screen editing** for the markdown and CSS fields: a button, and Esc to come back, that
  gives the field the whole window with the colouring, the scroll and the caret kept. It must
  re-measure through the highlighter's `measure()`, or the colours slide off the letters at the
  new size.
- **Ctrl+S saves** in the editor - the key everybody presses anyway, which today opens the
  browser's *Save page as*.
- **Unsaved changes** - a warning before leaving the editor with edits nobody saved.
