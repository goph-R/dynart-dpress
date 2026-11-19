<?php

namespace Dynart\Dpress\Theme;

/**
 * Page templates that came with a plugin (0.96.0)
 *
 * A theme's page templates are files, found by `ThemeService::pageTemplates()`. A plugin's are
 * declared - `PluginInterface::pageTemplates()` - because a plugin's views live under a namespace
 * of its own and there is no folder to look through. Both end up in the page editor's *Template*
 * select, and a page is set to one by the same stored name.
 *
 * **A theme can answer a plugin's template**: a page set to `music-player` tries the theme's
 * `dpress/content/page-template-music-player.phtml` first and the plugin's own after, the way a
 * theme overrides any other template by having a file of the same name. So the plugin ships
 * something that works and the theme gets the last word on how it looks.
 *
 * It is a registry and nothing more: nothing is resolved or read until a page is rendered.
 */
class PageTemplates {

    /** @var array<string, array{label: string, template: string, plugin: string}> */
    private array $templates = [];

    /**
     * @param string $name     What a page stores: plain `[a-z0-9-]`, up to 64 characters - the same
     *                         rule as a theme's, since the editor cannot tell them apart. Anything
     *                         else is refused, with `false`.
     * @param string $label    What the editor's select shows
     * @param string $template The view to render, `music_list:page-player`
     * @param string $plugin   Who it came from, for a message that has to say
     */
    public function add(string $name, string $label, string $template, string $plugin = ''): bool {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $name) !== 1 || $template === '') {
            return false;
        }
        $this->templates[$name] = [
            'label'    => $label !== '' ? $label : ucfirst(str_replace('-', ' ', $name)),
            'template' => $template,
            'plugin'   => $plugin,
        ];
        return true;
    }

    public function has(string $name): bool {
        return isset($this->templates[$name]);
    }

    /** The view a name stands for, or null when no plugin offers it */
    public function templateOf(string $name): ?string {
        return $this->templates[$name]['template'] ?? null;
    }

    /** @return array<string, string> name => label, by label */
    public function labels(): array {
        $labels = array_map(fn(array $template) => $template['label'], $this->templates);
        asort($labels, SORT_NATURAL | SORT_FLAG_CASE);
        return $labels;
    }
}
