<?php

namespace Dynart\Dpress\Content;

use Dynart\Micro\RouterInterface;
use Dynart\Dpress\Dpress;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Service\SettingService;

/**
 * The script and stylesheet a page with code in it needs, and nothing for a page without
 *
 * **A page with no code block loads neither.** The front end of this CMS ships no JavaScript at
 * all, and that is worth keeping for every page that does not need any - so the test is one
 * `str_contains` over HTML that is already in memory, the same guard `Shortcodes::expand()` uses.
 *
 * EnlighterJS is vendored rather than linked from a CDN, for the reason the video shortcode
 * embeds from `youtube-nocookie.com`: a visitor to somebody's site should not be announced to a
 * third party to read a code sample.
 */
class CodeAssets {

    /** What `data-enlighter-language` looks like in a rendered page */
    const MARKER = 'data-enlighter-language';

    /**
     * The stylesheets EnlighterJS ships, by the name the setting holds
     *
     * Each is self-contained - layout and colours in one file, about 13 KB - so a page loads one
     * and no base stylesheet under it.
     */
    const THEMES = [
        'enlighter'  => 'Enlighter',
        'atomic'     => 'Atomic',
        'beyond'     => 'Beyond',
        'bootstrap4' => 'Bootstrap',
        'classic'    => 'Classic',
        'dracula'    => 'Dracula',
        'droide'     => 'Droide',
        'eclipse'    => 'Eclipse',
        'godzilla'   => 'Godzilla',
        'minimal'    => 'Minimal',
        'monokai'    => 'Monokai',
        'mowtwo'     => 'MooTwo',
        'rowhammer'  => 'Rowhammer',
    ];

    const DEFAULT_THEME = 'enlighter';

    /**
     * Highlighting switched off, spelled as a value rather than as an empty one
     *
     * `SettingService::get()` treats `''` as *absent* and answers with the default, so an empty
     * setting cannot mean "off" - it means "whatever the default is". A site that wants no
     * highlighting has to be able to say so, and this is the word it says it in.
     */
    const NONE = 'none';

    public function __construct(
        protected RouterInterface $router,
        protected SettingService $settings,
    ) {}

    /**
     * Is there anything on this page for a highlighter to do?
     */
    public function needed(string $html): bool {
        return $this->theme() !== '' && str_contains($html, self::MARKER);
    }

    /**
     * The chosen theme, or '' when highlighting is switched off
     *
     * A name that is not one of ours is treated as off rather than guessed at - the setting is
     * writable by hand and by a plugin, and a stylesheet path is not something to build out of
     * whatever is in a row.
     */
    public function theme(): string {
        $name = (string)$this->settings->get(Setting::CODE_THEME, self::DEFAULT_THEME);
        return isset(self::THEMES[$name]) ? $name : '';   // `none`, and anything unrecognised, is off
    }

    /**
     * The `<link>` and `<script>` for a page that has code on it
     *
     * Rendered here rather than in a template so a theme cannot forget them and cannot get the
     * paths wrong. `defer` because nothing on the page waits for the highlighting.
     */
    public function tags(): string {
        $theme = $this->theme();
        if ($theme === '') {
            return '';
        }
        return '<link rel="stylesheet" href="'.htmlspecialchars($this->url('enlighterjs.'.$theme.'.min.css')).'">'
            ."\n".'<script src="'.htmlspecialchars($this->url('enlighterjs.min.js')).'" defer></script>'
            ."\n".'<script defer>document.addEventListener("DOMContentLoaded",function(){'
            .'EnlighterJS.init("pre['.self::MARKER.']","code",{indent:4,theme:"'.htmlspecialchars($theme).'"});'
            .'});</script>';
    }

    protected function url(string $file): string {
        return $this->router->url('/assets/enlighter/'.$file, ['v' => Dpress::VERSION]);
    }
}
