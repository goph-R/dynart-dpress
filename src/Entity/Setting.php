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

    #[Column(type: Column::TYPE_STRING, size: 100, primaryKey: true, notNull: true)]
    public string $name = '';

    #[Column(type: Column::TYPE_STRING)]
    public ?string $value = null;

    #[Column(type: Column::TYPE_DATETIME)]
    public ?string $updated_at = null;
}
