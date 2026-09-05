<?php

namespace Dynart\Dpress\Plugin;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\FormWidgets;
use Dynart\Micro\LoggerInterface;
use Dynart\Micro\Micro;
use Dynart\Micro\ViewInterface;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\Migrations;
use Dynart\Dpress\Block\Blocks;
use Dynart\Dpress\Content\Shortcodes;
use Dynart\Dpress\Controller\Admin\AssetController;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\SettingService;
use Dynart\Dpress\Theme\PageAssets;
use Throwable;

/**
 * Finds the installed plugins and loads the enabled ones
 *
 * A plugin is a folder under `plugins/` with a `plugin.ini` in it - the same rule as a theme, and
 * for the same reason: dropping a folder in is installing it. What a theme does not need and this
 * does is **code**. The manifest names a namespace and a class, so the loader registers one
 * autoloader per plugin and builds the class through the container.
 *
 * Which are enabled is a **setting**, so it is switchable while the site runs and audited like
 * every other setting.
 *
 * **Nothing here is allowed to throw.** Enabling lives in the database and the screen that
 * disables a plugin is in the admin, so a plugin that fataled on the way up would take away the
 * only way to turn it off. Every failure is caught, recorded on the `Plugin`, and shown on that
 * screen instead.
 */
class PluginService {

    const CONFIG_PATH = 'dpress.plugins_path';
    const DEFAULT_PATH = '~/plugins';

    /** The escape hatch: `dpress.plugins_off = 1` boots with none of them */
    const CONFIG_OFF = 'dpress.plugins_off';

    /** What makes a folder a plugin */
    const MANIFEST = 'plugin.ini';

    const EVENT_ENABLED = 'plugin:enabled';
    const EVENT_DISABLED = 'plugin:disabled';

    /** @var Plugin[]|null name => Plugin, memoised */
    private ?array $plugins = null;

    /** @var Plugin[] the ones `load()` actually loaded, in the order they were loaded */
    private array $loaded = [];

    private bool $loadWasRun = false;

    public function __construct(
        protected ConfigInterface $config,
        protected SettingService $settings,
        protected LoggerInterface $logger,
        protected EventServiceInterface $events,
    ) {}

    public function path(): string {
        return $this->config->getFullPath($this->config->get(self::CONFIG_PATH, self::DEFAULT_PATH));
    }

    public function isOff(): bool {
        return (bool)$this->config->get(self::CONFIG_OFF, false);
    }

    // --- what is on disk ---

    /**
     * Every plugin folder, by name
     *
     * @return Plugin[]
     */
    public function all(): array {
        if ($this->plugins !== null) {
            return $this->plugins;
        }
        $this->plugins = [];
        $base = $this->path();
        if (!is_dir($base)) {
            return $this->plugins;
        }
        foreach ((array)scandir($base) as $entry) {
            // anything starting with a dot is somebody's tooling, not a plugin - `.git`,
            // an editor's cache, a folder moved aside to turn it off
            if (str_starts_with($entry, '.') || !is_dir($base.'/'.$entry)) {
                continue;
            }
            $manifest = $base.'/'.$entry.'/'.self::MANIFEST;
            if (!is_file($manifest)) {
                continue;
            }
            $this->plugins[$entry] = new Plugin($entry, $base.'/'.$entry, $this->readManifest($manifest));
        }
        ksort($this->plugins);
        return $this->plugins;
    }

    public function has(string $name): bool {
        return isset($this->all()[$name]);
    }

    public function find(string $name): ?Plugin {
        return $this->all()[$name] ?? null;
    }

    protected function readManifest(string $path): array {
        $data = @parse_ini_file($path, false, INI_SCANNER_TYPED);
        return is_array($data) ? $data : [];
    }

    // --- which are enabled ---

    /**
     * The enabled names, in order
     *
     * **Order is subscription order**, which is what decides who goes first when two plugins
     * touch the same form. So it is a list rather than a set, and enabling appends.
     *
     * Wrapped, because this is read during boot and the settings table does not exist on a
     * database that has not been installed yet. No table, no plugins, and `dpress install` runs.
     *
     * @return string[]
     */
    public function enabledNames(): array {
        try {
            $value = (string)$this->settings->get(Setting::PLUGINS, '');
        } catch (Throwable $e) {
            return [];
        }
        $names = array_map('trim', explode(',', $value));
        return array_values(array_filter($names, fn($name) => $name !== ''));
    }

    public function isEnabled(string $name): bool {
        return in_array($name, $this->enabledNames(), true);
    }

    /**
     * Turns one on, at the end of the order
     */
    public function enable(string $name): void {
        if (!$this->has($name)) {
            throw new DpressException("There is no plugin called '$name' in ".$this->path().'.');
        }
        $names = $this->enabledNames();
        if (in_array($name, $names, true)) {
            return;
        }
        $names[] = $name;
        $this->settings->set(Setting::PLUGINS, join(',', $names));
        $this->events->emit(self::EVENT_ENABLED, [$name]);
    }

    /**
     * Turns one off
     *
     * Deliberately does **not** check that it is on disk: the whole reason to disable something
     * may be that it is enabled, broken and half deleted.
     */
    public function disable(string $name): void {
        $names = $this->enabledNames();
        $remaining = array_values(array_filter($names, fn($n) => $n !== $name));
        if ($remaining === $names) {
            return;
        }
        $this->settings->set(Setting::PLUGINS, join(',', $remaining));
        $this->events->emit(self::EVENT_DISABLED, [$name]);
    }

    // --- loading ---

    /**
     * Loads every enabled plugin, and never throws
     *
     * Called once during `init()`, from both the web app and the CLI. The window is narrow at
     * both ends: after this the attribute processor has run, so a controller registered later has
     * no routes; before it the settings are unreachable, so the list cannot be read at all.
     *
     * @return Plugin[] the ones that loaded
     */
    public function load(): array {
        if ($this->loadWasRun) {
            return $this->loaded;
        }
        $this->loadWasRun = true;
        if ($this->isOff()) {
            $this->logger->info('dpress: '.self::CONFIG_OFF.' is set, so no plugins were loaded');
            return [];
        }
        foreach ($this->enabledNames() as $name) {
            $plugin = $this->find($name);
            if ($plugin === null) {
                // enabled and no longer on disk. The same fail-soft a missing theme gets: the
                // site renders, and the screen says which one went.
                $this->plugins[$name] = new Plugin($name, '', [], Plugin::STATUS_MISSING);
                $this->logger->warning("dpress: the plugin '$name' is enabled but is not in ".$this->path());
                continue;
            }
            $this->loadOne($plugin);
        }
        return $this->loaded;
    }

    /**
     * @return Plugin[] the loaded ones, in load order
     */
    public function loaded(): array {
        return $this->loaded;
    }

    protected function loadOne(Plugin $plugin): void {
        try {
            $this->autoload($plugin);
            $instance = $this->instantiate($plugin);
            $this->contribute($plugin, $instance);
            $instance->register();
            // Last, and deliberately after `register()`. The container has no way to unregister
            // anything, so a plugin that throws part way through cannot be rolled back - but a
            // route is the one contribution that would otherwise leave a **public URL running
            // the code of a plugin that has just declared itself broken**. Registering the
            // controllers only once it has got through its own setup keeps that from happening.
            foreach ($instance->controllers() as $className) {
                Micro::add($className);
            }
            $plugin->status = Plugin::STATUS_ENABLED;
            $plugin->instance = $instance;
            $this->loaded[] = $plugin;
        } catch (Throwable $e) {
            // Throwable, not Exception: a plugin naming a class that does not exist raises an
            // Error, and that is the single most likely way for one to be broken.
            $plugin->fail($e->getMessage());
            $this->logger->error("dpress: the plugin '{$plugin->name}' failed to load: ".$e->getMessage());
        }
    }

    /**
     * One PSR-4 autoloader per plugin, mapping its namespace onto its `src/`
     *
     * This is the machinery a theme has no need of, and the reason a plugin cannot simply be a
     * folder of templates.
     */
    protected function autoload(Plugin $plugin): void {
        $prefix = $plugin->namespacePrefix();
        if ($prefix === '\\') {
            throw new DpressException("names no namespace in its ".self::MANIFEST);
        }
        $base = $plugin->path.'/src/';
        spl_autoload_register(function (string $class) use ($prefix, $base): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = $base.$relative.'.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    protected function instantiate(Plugin $plugin): PluginInterface {
        $className = $plugin->className();
        if ($className === '') {
            throw new DpressException('names no class in its '.self::MANIFEST);
        }
        if (!class_exists($className)) {
            throw new DpressException("the class $className was not found in ".$plugin->path.'/src');
        }
        if (!is_subclass_of($className, PluginInterface::class)) {
            throw new DpressException("$className does not implement ".PluginInterface::class);
        }
        if (!Micro::hasInterface($className)) {
            Micro::add($className);
        }
        return Micro::get($className);
    }

    /**
     * Hands everything the plugin declared to whatever owns it
     *
     * Services first, because everything else may depend on one. Controllers are **not** here -
     * `loadOne()` registers those after `register()` has succeeded, so a broken plugin leaves no
     * routes behind. The rest do stay registered on a failure: an entity and its migration
     * staying is what keeps its table from being dropped out from under its data, and a widget
     * or a permission left over is untidy rather than dangerous.
     */
    protected function contribute(Plugin $record, PluginInterface $plugin): void {
        foreach ($plugin->services() as $interface => $className) {
            Micro::add($interface, $className === $interface ? null : $className);
        }
        foreach ($plugin->views() as $namespace => $folder) {
            Micro::get(ViewInterface::class)->addFolder($namespace, $folder);
        }
        foreach ($plugin->widgets() as $type => $view) {
            Micro::get(FormWidgets::class)->add($type, $view);
        }
        foreach ($plugin->blocks() as $type => $definition) {
            Micro::get(Blocks::class)->add($type, $definition);
        }
        $this->contributePageAssets($record, $plugin);
        foreach ($plugin->shortcodes() as $name => $declared) {
            // `name => handler` or `name => [handler, kind]`, so the common case stays one line
            [$handler, $kind] = is_array($declared) && isset($declared[1])
                ? $declared : [$declared, Shortcodes::INLINE];
            Micro::get(Shortcodes::class)->add($name, $handler, $kind);
        }
        foreach ($plugin->entities() as $className) {
            Micro::get(EntityManager::class)->registerEntity($className);
        }
        foreach ($plugin->migrations() as $className) {
            Micro::get(Migrations::class)->add($className);
        }
        $permissions = Micro::get(Permissions::class);
        foreach ($plugin->permissions() as $permission => $group) {
            $permissions->add($permission, $group);
        }
    }

    /**
     * The plugin's own files, into the head of a visitor's page
     *
     * Named `plugin:<name>:<file>`, so a plugin loaded twice registers one of each rather
     * than two, and so a theme that wants to answer one of them can say which.
     *
     * The URL carries the **plugin's** version and not the CMS's: a plugin releasing a new
     * stylesheet should expire that stylesheet, and upgrading dpress should not expire a font
     * nothing touched. The same reasoning as a theme's assets, one folder over.
     *
     * An extension that is neither `css` nor `js` is skipped rather than guessed at. A font is
     * fetched *by* a stylesheet, not linked from a page, and a `<link>` to a `.woff2` is a
     * download the browser does nothing with.
     */
    protected function contributePageAssets(Plugin $record, PluginInterface $plugin): void {
        $assets = Micro::get(PageAssets::class);
        foreach ($plugin->pageAssets() as $file => $needle) {
            $file = (string)$file;
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($extension !== 'css' && $extension !== 'js') {
                $this->logger->warning(
                    "dpress: the plugin '{$record->name}' offers '$file' to the page, and only"
                        ." css and js can go in a head."
                );
                continue;
            }
            // A closure and not a built URL, because **the loader runs in the CLI too** and
            // there is no router to ask for one there - a plugin doing this eagerly would be
            // recorded as failed by `dpress upgrade` and its tables would never be made. The
            // address is worked out the first time a page wants the file.
            $assets->add(
                'plugin:'.$record->name.':'.$file,
                function () use ($record, $file, $extension): string {
                    $url = AssetController::pluginUrl($record->name, $file, $record->version());
                    return $extension === 'css'
                        ? PageAssets::styleTag($url) : PageAssets::scriptTag($url);
                },
                (string)$needle
            );
        }
    }
}
