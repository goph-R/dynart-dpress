# Plugins

**Status: built** (dpress 0.23.0, needs micro 0.20.0).

A plugin is **a folder under `plugins/` with a `plugin.ini` in it** — the same rule a theme
follows, because dropping a folder in should be all installing takes. What a theme does not need
and a plugin does is code, so the manifest names a namespace and a class.

```
plugins/reading-time/
  plugin.ini
  src/ReadingTimePlugin.php
  src/Entity/ReadingTime.php
  src/Migration/CreateReadingTimeTable.php
  views/widget/minutes.phtml
  assets/reading-time.js
```

```ini
title       = "Reading time"
description = "Shows how long a post takes to read."
version     = 0.1.0
author      = gopher
namespace   = "Dynart\\ReadingTime"
class       = "Dynart\\ReadingTime\\ReadingTimePlugin"
```

A working example of everything below ships in the development app at
`dynart-dpress-app/plugins/reading-time/`. It exists to be read and to be tested against: if it
works, the plugin system works.

---

## 1. The class

Extend `AbstractPlugin` and override what you need. Every method has a no-op default, so a plugin
that adds one field type is four lines, and a method added to the interface later does not break
every plugin in existence.

```php
class ReadingTimePlugin extends AbstractPlugin {

    public function services(): array    { return [ReadingTimeService::class => ReadingTimeService::class]; }
    public function controllers(): array { return [ReadingTimeController::class]; }
    public function entities(): array    { return [Entity\ReadingTime::class]; }
    public function migrations(): array  { return [Migration\CreateReadingTimeTable::class]; }
    public function widgets(): array     { return ['reading_time' => 'reading_time:widget/minutes']; }
    public function views(): array       { return ['reading_time' => dirname(__DIR__).'/views']; }
    public function assets(): array      { return ['reading-time.js']; }
    public function permissions(): array { return ['reading_time.override' => 'reading_time']; }

    public function register(): void { /* events, form and query builders */ }
}
```

**Declarative first.** Everything that can be a list is one, so the loader can read what a plugin
*would* do without running it. `register()` is the escape hatch for the rest.

Two ordering rules the loader enforces, both of which will bite otherwise:

- **`services()` before everything else**, because a controller or an entity may depend on one.
  Declare a service here rather than `Micro::add()`-ing it in `register()`: controllers are
  registered first, and a controller whose dependency arrives later only fails when somebody
  visits it.
- **`controllers()` after `register()` has succeeded.** See §4.

## 2. What you get for free

Most of this was already open before there was a loader — the plan's §10 called it "designing for
plugins without building them", and it was accurate.

| To do this | Do this |
|---|---|
| add or change a form field | subscribe to `form.<name>:created`, then `addFields()` / `addValidator()` |
| add a whole form | `FormFactory::add()` |
| change what a listing returns | subscribe to `query.<name>:created`, then `addCondition()` |
| add a route | name the controller in `controllers()` — the `#[Route]` attributes are found by themselves |
| add a table | `entities()` + `migrations()`, then `dpress upgrade` |
| add a permission | `permissions()` — the role editor picks it up, and the admin role holds it implicitly |
| add a field type | `widgets()` + `views()` |

**Subscribe with a Micro callable**, `[MyThing::class, 'onEvent']`, never a closure. The event
service resolves it through the container *when the event fires*, so an enabled plugin that hooks
the content form builds nothing on a page view. That is what keeps the mechanism free — see
[performance.md](performance.md).

Two rules about queries worth knowing before you attach a condition to one you did not write:

- **You can narrow a query, never widen it.** There is no `removeCondition()` and conditions are
  ANDed, so a subscriber cannot strip `status = 'published'` off a public listing.
- **Use `Query::nextParamName('base')`** for a placeholder. Reusing a name already bound to a
  different value throws rather than silently corrupting the other condition.

## 3. Where a plugin loads, and what it may replace

Everything happens inside `init()`, at one point, and both ends of it are forced:

```
DpressServices::register()      bindings only, nothing resolved yet
registerControllers()
>>> plugins load here <<<
initServices()                  resolves the services, registers entities, migrations, forms
runMiddlewares()                AttributeProcessor - the one look it takes at the container
```

- **After** `register()`, because reading the enabled list needs the database. So a plugin **cannot**
  replace `Database`, `EntityManager`, `SettingService` or the config layer — they are already
  built by the time the list can be read.
- **Before** `initServices()`, because `Micro::get()` caches singletons forever. So a plugin **can**
  replace `ContentService`, `MediaService`, `MenuService` and the rest, by registering a subclass:
  `Micro::add` checks `is_subclass_of`, so this narrows behaviour rather than swapping it out.
- **Before `runMiddlewares()`**, which is when the attribute processor looks at the container once.
  A controller registered any later has no routes and says nothing about it.

Both the web app and the CLI load plugins. A plugin registered on only one path is invisible to
`dpress upgrade`, and its tables never get built.

## 4. When a plugin breaks

**Nothing in the loader is allowed to throw.** Enabling lives in the database and the screen that
disables a plugin is in the admin, so a plugin that fataled on the way up would take away the only
way to turn it off.

| what happened | what you get |
|---|---|
| the class throws, or is missing, or is not a plugin | **Failed**, with the message, on `/admin/plugins` |
| enabled but no longer on disk | **Missing** |
| no `setting` table yet (fresh database) | no plugins, and `dpress install` runs |
| something subtler is broken | `dpress.plugins_off = 1` in the ini boots with none of them |

One plugin failing does not stop the next. A failed plugin **registers no controllers**, because
that is the one contribution that would otherwise leave a public URL running the code of a plugin
that has just declared itself broken — the loader registers them only once `register()` has
returned. The container has no way to unregister anything, so its services, widgets and
permissions do stay; those are untidy rather than dangerous, and its entity and migration staying
is what keeps its table from being dropped out from under its data.

## 5. Widgets

A field type is `type => view path` in `widgets()`, plus a view folder in `views()`. The template
gets `$form`, `$name` and `$field`, exactly like the CMS's own:

```php
<?php $id = $form->idByNameAndField($name, $field) ?>
<input id="<?= $id ?>" name="<?= $form->inputName($name) ?>" type="number"
       value="<?= $form->value($name, true) ?>" data-reading-time-input>
```

An unregistered type renders an HTML comment naming it and logs what *is* registered — it used to
render an empty string and say nothing anywhere.

**Behaviour goes in `assets()`, not in the template.** An admin screen is reached by a partial
navigation with no page load, and inserted HTML does not run its scripts; files listed in
`assets()` are rendered into the layout instead, which is where they will still be. Register the
binder with `Dpress.addInit()` so it runs after every swap too, and guard against binding twice:

```js
window.Dpress.addInit(function (root) {
    root.querySelectorAll('[data-reading-time]').forEach(function (field) {
        if (field.dataset.readingTimeBound) { return; }
        field.dataset.readingTimeBound = '1';
        // ...
    });
});
```

Only `.js` and `.css` are loaded, only from the plugin's own `assets/`, and only while the plugin
is loaded. The filename is matched against `[A-Za-z0-9_-]+\.[A-Za-z0-9]+` with no slashes, so it
cannot climb out of the folder.

## 6. Turning one on

```bash
vendor/bin/dpress plugin:list
vendor/bin/dpress plugin:enable -name reading-time
vendor/bin/dpress upgrade            # if it brings tables
```

or the **Plugins** screen in the admin, which has the same three operations as group actions and
is the one place a *failed* plugin can be seen and switched off.

Enabling appends to `Setting::PLUGINS`, and **that order is subscription order** — it decides who
goes first when two plugins add a field to the same form.

## 7. What was deliberately left out

- **Interfaces for the domain services.** The plan promised `ContentServiceInterface`; it does not
  exist, and extracting one touches every constructor. Subclass-and-rebind works meanwhile.
- **Named layout slots** for the front end. Superseded in 0.37.0 by *places*: a theme declares
  them, a menu is assigned to one and blocks are ordered in one. What is still missing is a place
  *inside* a post, and a way for a block to know which page it is on — see
  [comments.md](comments.md) §3, which is the first thing to want both.
- **Event priorities.** The plugin list's order is the ordering.
- **Composer-installed plugins.** Already possible for anything needing only routes and services:
  name the namespace in `app.scan_namespaces` and the attribute processor finds it. Not worth a
  second discovery path.
