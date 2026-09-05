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

    const DEFAULT_THEME = 'dracula';

    /**
     * Highlighting switched off, spelled as a value rather than as an empty one
     *
     * `SettingService::get()` treats `''` as *absent* and answers with the default, so an empty
     * setting cannot mean "off" - it means "whatever the default is". A site that wants no
     * highlighting has to be able to say so, and this is the word it says it in.
     */
    const NONE = 'none';

    /**
     * The two rules the highlighter's own stylesheets leave out
     *
     * Room inside the block first.
     *
     * `.enlighter-default` is the element the theme paints its background on, and it sets
     * `padding: 0` - so the first line of code sits against the top edge of the colour and the
     * last against the bottom. Vertical only: the code area under it is a `display: table` and
     * horizontal padding there fights the line layout.
     *
     * Emitted **after** the theme's stylesheet rather than from a layout, for two reasons. Both
     * selectors are one class, so whichever comes last wins and the theme link is added inside
     * `</head>` after the layout's own `<style>`. And a theme author should not have to know this
     * is needed - there is one copy, and it arrives with the thing it corrects.
     *
     * The vendored file stays unmodified, which is what keeps the MPL obligation to a notice.
     *
     * `overscroll-behavior-x` is the other one, and it is about the phone: a block that scrolls
     * sideways is a block a thumb swipes in, and without this the swipe chains to the page once
     * the code runs out - which under Chrome's gesture navigation and on iOS is the *back*
     * gesture. Reading a wide line should not be able to leave the article.
     */
    const STYLE = '<style>.enlighter-default{padding:12px 0}'
        .'.enlighter-default.enlighter-overflow-scroll{overscroll-behavior-x:contain}</style>';

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
     * The tags for one page, or nothing when there is nothing to highlight
     *
     * The shape `PageAssets` calls: it hands over the finished HTML and takes markup back.
     * The registration also carries `MARKER` as its needle, which looks like the same test
     * twice and is not - the needle is a `str_contains` that keeps the container from
     * building this class at all on a page with no code on it, and `needed()` is the actual
     * question, which also asks whether highlighting is switched on.
     */
    public function tagsFor(string $html): string {
        return $this->needed($html) ? $this->tags() : '';
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
            ."\n".self::STYLE
            ."\n".'<script src="'.htmlspecialchars($this->url('enlighterjs.min.js')).'" defer></script>'
            ."\n".'<script defer>document.addEventListener("DOMContentLoaded",function(){'
            // `init(blocks, inline, options)` - the second selector is for **inline** snippets and
            // has to match nothing. Given `code` it rebuilds every backtick span in somebody's
            // prose as a code sample, which is not what writing one asks for.
            .'EnlighterJS.init("pre['.self::MARKER.']","code.enlighter-inline",'
            // `textOverflow` defaults to `break`, which wraps a long line - and code is the one
            // kind of text where a wrap changes what it says: the indent stops meaning a nesting
            // level, and a shell command comes apart mid-flag. Everywhere else code is read it
            // scrolls. Narrow screens are where it matters and where the default is worst.
            .'{indent:4,textOverflow:"scroll",theme:"'.htmlspecialchars($theme).'"});'
            .'});</script>';
    }

    protected function url(string $file): string {
        return $this->router->url('/assets/enlighter/'.$file, ['v' => Dpress::VERSION]);
    }
}
