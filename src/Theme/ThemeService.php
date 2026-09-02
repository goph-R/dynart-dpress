<?php

namespace Dynart\Dpress\Theme;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Service\SettingService;

/**
 * Finds the installed themes and decides which one renders
 *
 * A theme is a folder under `themes/` with a `theme.ini` in it. Nothing has to be registered:
 * dropping a folder in is installing it, which is what a theme folder is for.
 *
 * The active theme is a **setting**, not a config value, so it can be switched while the site
 * runs - and the switch is audited, like every other setting.
 */
class ThemeService {

    const EVENT_SWITCHED = 'theme:switched';

    const CONFIG_PATH = 'dpress.themes_path';
    const DEFAULT_PATH = '~/themes';

    /** What makes a folder a theme */
    const MANIFEST = 'theme.ini';

    /** Rendered when no theme is set, or the set one has gone missing */
    const FALLBACK = '';

    /**
     * What the built-in templates render, since they have no `theme.ini` to declare it
     *
     * `views/layout.phtml` puts `main` beside the logo and always has. A theme declares its
     * places in its manifest and the fallback has no manifest, so `places()` came back empty and
     * the menu editor said a menu had nowhere to render - on a site whose header was rendering
     * one. Declared here instead of nowhere.
     */
    const BUILT_IN_PLACES = ['main' => 'Main'];

    private ?array $themes = null;

    public function __construct(
        protected ConfigInterface $config,
        protected ViewInterface $view,
        protected SettingService $settings,
        protected EventServiceInterface $events,
    ) {}

    public function path(): string {
        return rtrim($this->config->getFullPath(
            (string)$this->config->get(self::CONFIG_PATH, self::DEFAULT_PATH)
        ), '/\\');
    }

    /**
     * Every installed theme, keyed by folder name
     *
     * @return array [name => ['name', 'title', 'description', 'author', 'version', 'places']]
     */
    public function all(): array {
        if ($this->themes !== null) {
            return $this->themes;
        }
        $this->themes = [];
        $base = $this->path();
        if (!is_dir($base)) {
            return $this->themes;
        }
        foreach ((array)scandir($base) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($base.'/'.$entry)) {
                continue;
            }
            $manifest = $base.'/'.$entry.'/'.self::MANIFEST;
            if (!is_file($manifest)) {
                continue;
            }
            $this->themes[$entry] = $this->readManifest($entry, $manifest);
        }
        ksort($this->themes);
        return $this->themes;
    }

    public function has(string $name): bool {
        return array_key_exists($name, $this->all());
    }

    public function get(string $name): ?array {
        return $this->all()[$name] ?? null;
    }

    /**
     * The theme the site is set to render with
     *
     * Falls back to none when the setting names a theme that is not installed - a missing theme
     * should leave the site rendering the built in templates, not fatal on every page.
     */
    public function active(): string {
        $name = (string)$this->settings->get(Setting::THEME, self::FALLBACK);
        return $name !== '' && $this->has($name) ? $name : self::FALLBACK;
    }

    /**
     * The places the active theme declares, so the menu editor can offer them
     *
     * @return array [place => label]
     */
    public function places(): array {
        $active = $this->active();
        if ($active === self::FALLBACK) {
            return self::BUILT_IN_PLACES;
        }
        $theme = $this->get($active);
        // `active()` only ever answers with a theme that exists or with the fallback, so the null
        // is defensive - and whatever renders in that case is the built-in layout. A theme that
        // exists and declares no places genuinely renders none, and the warning is right to say so.
        return $theme === null ? self::BUILT_IN_PLACES : $theme['places'];
    }

    /**
     * @throws DpressException if there is no such theme
     */
    public function activate(string $name): void {
        if ($name !== self::FALLBACK && !$this->has($name)) {
            throw new DpressException("There is no theme called '$name' in ".$this->path().'.');
        }
        $this->settings->set(Setting::THEME, $name);
        $this->apply();
        $this->events->emit(self::EVENT_SWITCHED, [$name]);
    }

    /**
     * Points the view at the active theme
     *
     * Called during boot. `View::setTheme()` takes a path it checks *before* every namespace
     * folder, which is what lets a theme override any template of the CMS.
     */
    public function apply(): void {
        $name = $this->active();
        $this->view->setTheme($name === self::FALLBACK ? '' : $this->path().'/'.$name);
    }

    /**
     * Reads a `theme.ini`
     *
     * Missing keys are not an error: a theme that is nothing but a folder and an empty manifest
     * is a valid theme that overrides nothing.
     */
    protected function readManifest(string $name, string $path): array {
        $data = @parse_ini_file($path, false, INI_SCANNER_TYPED);
        if (!is_array($data)) {
            $data = [];
        }
        $places = [];
        foreach ((array)($data['places'] ?? []) as $place) {
            $place = trim((string)$place);
            if ($place !== '') {
                $places[$place] = ucfirst(str_replace(['_', '-'], ' ', $place));
            }
        }
        return [
            'name'        => $name,
            'title'       => (string)($data['title'] ?? $name),
            'description' => (string)($data['description'] ?? ''),
            'author'      => (string)($data['author'] ?? ''),
            'version'     => (string)($data['version'] ?? ''),
            'places'      => $places,
        ];
    }
}
