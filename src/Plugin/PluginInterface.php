<?php

namespace Dynart\Dpress\Plugin;

/**
 * What a plugin may contribute
 *
 * Declarative first, with `register()` as the escape hatch. Everything that can be a list is a
 * list, for the same reason a list screen's columns and row actions are arrays: the loader can
 * read them without running anything, so `plugin:list` can say what a plugin *would* do, and a
 * plugin that only adds a field type needs no code at all beyond naming it.
 *
 * `AbstractPlugin` gives every one of these a no-op default. Implement this interface directly
 * only if you have a reason not to extend it.
 */
interface PluginInterface {

    /**
     * DI bindings, as `interface => class`
     *
     * Registered before the CMS resolves its own services, so a plugin can put its own class in
     * front of one - `[ContentService::class => MyContentService::class]`. `Micro::add()` checks
     * that the replacement is a subclass, so this narrows behaviour rather than replacing it
     * wholesale. It cannot reach the config, the database or the settings: those are already
     * built by the time the plugin list can be read at all.
     */
    public function services(): array;

    /**
     * Controllers, as a list of class names
     *
     * Registering one is all it takes - the routes come from its `#[Route]` attributes, which the
     * attribute processor picks up from anything in the container.
     */
    public function controllers(): array;

    /** Entity classes, as a list. They are registered with the entity manager. */
    public function entities(): array;

    /**
     * Migration classes, as a list
     *
     * The runner sorts by `version()` across everything registered, so pick something that sorts
     * after the CMS's own - a date works: `2026_08_06_001_create_my_table`.
     */
    public function migrations(): array;

    /** Field types, as `type => view path`, for `FormWidgets` */
    public function widgets(): array;

    /**
     * Block types this plugin adds
     *
     * The same definition `Blocks::add()` takes - `title`, `render`, and optionally `fields`
     * and `prepare`. A block is a registration and never a migration, so a plugin bringing a
     * new kind of block brings no table with it.
     *
     * @return array<string, array> type => definition
     */
    public function blocks(): array;

    /**
     * Files out of this plugin's `assets/` to put in the head of a **visitor's** page
     *
     * Not the same list as `assets()`, which is the admin's. A field type's behaviour belongs
     * on the screen that renders the field; a set of icons or a button's stylesheet belongs on
     * the site, and until 0.59.0 a plugin had no way to put one there.
     *
     * The value is a **needle**: a plain substring the finished page must contain for the file
     * to be loaded at all, so a stylesheet for an icon font is on the pages with an icon on
     * them and nowhere else. `''` means every page.
     *
     *     ['fontawesome.css' => 'class="fa-', 'kofi.css' => '']
     *
     * `.css` becomes a stylesheet and `.js` a deferred script; anything else is ignored,
     * because a font is fetched *by* a stylesheet rather than linked from a page.
     *
     * @return array<string, string> file => needle
     */
    public function pageAssets(): array;


    /**
     * Shortcodes, as `name => handler` or `name => [handler, kind]`
     *
     * The handler is a Micro callable, `[MyShortcode::class, 'render']`, so nothing is built until
     * a page containing one is rendered. `kind` is `Shortcodes::BLOCK` or `INLINE` and decides
     * whether it may sit inside a paragraph - see `ShortcodeRenderer::onDocumentParsed()`.
     */
    public function shortcodes(): array;

    /** Permissions, as `permission => group`. They appear in the role editor by themselves. */
    public function permissions(): array;

    /** View folders, as `namespace => absolute path` */
    public function views(): array;

    /**
     * Files from this plugin's `assets/` folder to load on every admin screen
     *
     * A list of names - `['reading-time.js', 'reading-time.css']`. They are rendered into the
     * admin layout, **not** into the widget's own template: an admin screen arrives through a
     * partial navigation with no page load, and inserted HTML does not run its scripts.
     *
     * Only `.js`, `.css` and `.svg`, and only while the plugin is loaded.
     */
    public function assets(): array;

    /**
     * Everything that is not a list
     *
     * Subscribe to events, register form and query builders, whatever else. Called last, after
     * every list above has been registered, so the services and views are already in place.
     *
     * **Subscribe with a Micro callable**, `[MyThing::class, 'onEvent']`, rather than a closure:
     * the event service resolves it through the container when the event fires, so a plugin that
     * is enabled but idle costs one array entry and nothing else.
     */
    public function register(): void;
}
