<?php

namespace Dynart\Dpress\Service;

use Dynart\Micro\LoggerInterface;

/**
 * The sections of the admin's navigation - the core's and the plugins' alike
 *
 * A registry, like `SettingFields`, and for its reason: the navigation was a constant list in
 * `AbstractAdminController::navigation()`, so a plugin could build an admin screen and nobody
 * could find it without being told its address. The core's own sections go through `add()` too
 * (`DpressServices::registerAdminSections()`), so the call a plugin makes is one the CMS makes.
 *
 * A section is:
 *
 * | | |
 * |---|---|
 * | `key` | what a screen's `section()` answers to be the current one; unique |
 * | `label` | what the navigation says |
 * | `route` | where it goes, for `Router::url()` |
 * | `permission` | who sees it; `''` for everybody who may open the admin |
 * | `icon` | a core icon's name |
 * | `icon_file` | or an `.svg` of the plugin's own, as an absolute path - `PluginService` resolves it |
 * | `after` / `before` | a key it goes beside; a plugin's goes after the content group if neither |
 *
 * **The order is worked out when it is read**, not when a section is added, so "after Media"
 * means after Media whichever of the two was added first - and a plugin loaded in the CLI, where
 * the core's sections are never added, costs nothing.
 */
class AdminSections {

    /** Where a plugin's section goes when it says nothing: the end of the content group */
    const DEFAULT_AFTER = 'blocks';

    /** @var array<string, array> key => section, in the order they were added */
    private array $sections = [];

    /** @var string[] the keys a plugin added, which are the ones a default position applies to */
    private array $fromPlugins = [];

    public function __construct(protected LoggerInterface $logger) {}

    /**
     * @param string $source `core`, or the name of the plugin adding it - which is what a warning
     *                       about it names
     */
    public function add(array $section, string $source = 'core'): void {
        $key = trim((string)($section['key'] ?? ''));
        if ($key === '' || trim((string)($section['label'] ?? '')) === '' || trim((string)($section['route'] ?? '')) === '') {
            $this->logger->warning("Dpress: an admin section from '$source' needs a key, a label and a route.");
            return;
        }
        // A plugin cannot take the Posts entry by naming its own section `content`: the first to
        // claim a key keeps it, and the second is told so where somebody will look.
        if (isset($this->sections[$key])) {
            $this->logger->warning("Dpress: the admin section '$key' from '$source' is already taken, and was left out.");
            return;
        }
        $this->sections[$key] = [
            'key'        => $key,
            'label'      => (string)$section['label'],
            'route'      => (string)$section['route'],
            'permission' => (string)($section['permission'] ?? ''),
            'icon'       => (string)($section['icon'] ?? ''),
            'icon_file'  => (string)($section['icon_file'] ?? ''),
            'after'      => (string)($section['after'] ?? ''),
            'before'     => (string)($section['before'] ?? ''),
        ];
        if ($source !== 'core') {
            $this->fromPlugins[] = $key;
        }
    }

    public function has(string $key): bool {
        return isset($this->sections[$key]);
    }

    /**
     * Every section, in the order the navigation shows them
     *
     * The ones with no position first, as they were added; then each positioned one beside the
     * section it named - after it, or before it. A key that is not there (a plugin switched off,
     * a typo) puts it where a plugin's goes by default, and failing that at the end.
     *
     * @return array[]
     */
    public function sections(): array {
        $ordered = [];
        $placed = [];
        foreach ($this->sections as $key => $section) {
            if (!$this->positioned($section)) {
                $ordered[] = $section;
            } else {
                $placed[] = $section;
            }
        }
        // how many have gone after each key so far, so two plugins after Blocks keep the order
        // they were added in rather than each landing in front of the last
        $alreadyAfter = [];
        foreach ($placed as $section) {
            [$target, $after] = $section['before'] !== ''
                ? [$section['before'], false]
                : [$section['after'] !== '' ? $section['after'] : self::DEFAULT_AFTER, true];
            $at = $this->indexOf($ordered, $target);
            if ($at === null && $target !== self::DEFAULT_AFTER) {
                [$target, $after] = [self::DEFAULT_AFTER, true];
                $at = $this->indexOf($ordered, $target);
            }
            if ($at === null) {
                $ordered[] = $section;
            } else if ($after) {
                $offset = $alreadyAfter[$target] ?? 0;
                array_splice($ordered, $at + 1 + $offset, 0, [$section]);
                $alreadyAfter[$target] = $offset + 1;
            } else {
                // before a key: each one goes in front of it, which is after the ones before it
                array_splice($ordered, $at, 0, [$section]);
            }
        }
        return $ordered;
    }

    /** A plugin's section is always placed - by what it said, or by the default */
    protected function positioned(array $section): bool {
        return $section['after'] !== '' || $section['before'] !== '' || in_array($section['key'], $this->fromPlugins, true);
    }

    protected function indexOf(array $sections, string $key): ?int {
        foreach ($sections as $index => $section) {
            if ($section['key'] === $key) {
                return $index;
            }
        }
        return null;
    }
}
