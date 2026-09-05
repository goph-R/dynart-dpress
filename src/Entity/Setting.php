<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * One site-wide setting
 *
 * Audited: "who turned registration on" is the same kind of question as "who granted this role".
 * Because it is keyed by name, one row per setting, the mirror gives per-setting history for
 * free - `(name, rev_id)` reads back as the whole timeline of one switch, no replay needed.
 *
 * Settings hold what an editor may change while the site runs. Anything needed *before* the
 * database is reachable - the connection itself, the JWT secret - stays in `dpress.ini`.
 */
#[Auditable]
#[Table(name: 'setting')]
class Setting extends Entity {

    protected static string $eventName = 'setting';

    /** The theme the site renders with */
    const THEME = 'theme';

    /**
     * The enabled plugins, comma separated
     *
     * A list rather than a set, because the order is subscription order - it decides who
     * goes first when two plugins add a field to the same form. Enabling appends.
     */
    const PLUGINS = 'plugins';

    const SITE_NAME = 'site_name';
    const SITE_DESCRIPTION = 'site_description';

    /**
     * A file the site is branded with, as a **media id**, chosen through the picker
     *
     * This used to be a path, on the reasoning that a header logo is chrome: it renders on pages
     * that show no content at all, it has to work before anything has been uploaded, and deleting
     * a library item must not be able to take the header down with it. Every one of those is a
     * real concern and none of them needs a path to answer - **they need a fallback**, which is
     * what `dpress.default_logo` and `dpress.default_icon` are.
     *
     * So: the id when there is one and the file is still there, and the configured default when
     * there is not. An empty setting, a deleted item, a purged one and a fresh installation all
     * take the same branch, which is the point - there is one way for this to be missing rather
     * than four.
     */
    const SITE_LOGO = 'site_logo';
    const SITE_ICON = 'site_icon';

    /**
     * What to show when no media is chosen, or when what was chosen is gone
     *
     * A path, resolved against `app.base_url` exactly as the setting used to be, and **empty by
     * default** - dpress ships no logo and cannot know what a site keeps in its own `static`
     * folder. The application sets it in `dpress.ini`.
     */
    const CONFIG_DEFAULT_LOGO = 'dpress.default_logo';
    const CONFIG_DEFAULT_ICON = 'dpress.default_icon';

    /**
     * Which EnlighterJS stylesheet colours the code blocks
     *
     * A name from `CodeAssets::THEMES`, or '' for no highlighting at all - which is a real choice,
     * not a broken one: the code still renders, as a plain block, and the page loads no script.
     *
     * Nothing about a theme is stored in a document, so changing this changes every post at once
     * and needs no re-render. That is the whole reason the highlighting is not done at save.
     */
    const CODE_THEME = 'code_theme';

    const REGISTRATION_OPEN = 'registration_open';
    const POSTS_PER_PAGE = 'posts_per_page';

    /**
     * How many posts the RSS feed carries
     *
     * Separate from `posts_per_page` although both are "how many posts": a page is paginated and
     * a feed is not, so the front page showing ten is a reader seeing the rest by clicking, while
     * a feed showing ten is the eleventh post never reaching anybody who was away for a fortnight.
     * `Feed` clamps it, because the cost of a large number is paid on every fetch forever.
     */
    const FEED_ITEMS = 'feed_items';

    /**
     * The tag that puts a post at the top of the front page
     *
     * **A tag rather than a column**, because an author already knows how to tag a post and
     * un-featuring is removing one - no new screen, no new concept and no migration. A setting
     * rather than the word `featured` hardcoded, so a Hungarian site can call it `kiemelt`.
     *
     * Empty means no featured posts at all, which is what a site that does not want a strip on
     * its front page should be able to say.
     */
    const FEATURED_TAG = 'featured_tag';

    /** What a site gets when it has never said, and the word most sites would have chosen */
    const DEFAULT_FEATURED_TAG = 'featured';

    /**
     * How a date is written on a page, and which clock it is written against
     *
     * The two belong together. Every timestamp is stored UTC, so a format on its own prints UTC -
     * and a post published at half past midnight in Budapest then shows the previous day to
     * everybody. See `Dates`.
     */
    /**
     * Where a post lives: under `/post/` or at the root of the site
     *
     * A pair of URLs for one post is what a move costs, so this is a **site** decision and
     * not a preference: every backlink, every search result and every link anybody ever wrote
     * points at one shape. `post` is what dpress has always done and stays the default, so an
     * upgrade moves nothing; `root` is what WordPress does, and what a blog coming from one
     * needs if its addresses are to survive the move.
     *
     * It is safe to change either way: `/post/<slug>` keeps answering with a **301** to
     * wherever the post lives now, so the old addresses are redirects rather than dead ends.
     */
    const POST_PATH = 'post_path';

    /** `/post/<slug>` - the default, and what every dpress site has had until now */
    const POST_PATH_PREFIXED = 'post';

    /** `/<slug>` - one flat namespace, which the globally unique slug already guarantees */
    const POST_PATH_ROOT = 'root';

    const POST_PATHS = [self::POST_PATH_PREFIXED, self::POST_PATH_ROOT];

    /**
     * Whether a bare `http://` or `https://` in prose becomes a link
     *
     * **On unless a site says otherwise**, which is the opposite of how a new setting usually
     * arrives: somebody writing a URL in a sentence already meant a link, and making them
     * write it twice is a tax on the common case. Off is for a site that quotes URLs as
     * examples - a page about writing markdown - where linking them is a nuisance.
     *
     * It is applied when a document is rendered, so changing it wants `dpress
     * content:rerender` behind it.
     */
    const AUTOLINK = 'autolink';

    const DATE_FORMAT = 'date_format';
    const TIMEZONE = 'timezone';

    #[Column(type: Column::TYPE_STRING, size: 100, primaryKey: true, notNull: true)]
    public string $name = '';

    #[Column(type: Column::TYPE_STRING)]
    public ?string $value = null;

    #[Column(type: Column::TYPE_DATETIME)]
    public ?string $updated_at = null;
}
