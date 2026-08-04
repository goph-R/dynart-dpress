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

    const SITE_NAME = 'site_name';
    const SITE_DESCRIPTION = 'site_description';

    /**
     * A file the site is branded with, named by path rather than by media id
     *
     * `/static/logo.svg` is resolved against `app.base_url`. Not a media item: the library is
     * content, and a header logo is chrome - it has to render before anything has been uploaded,
     * on a page that may show no content at all, and deleting it from the library must not be
     * able to take the header down with it.
     */
    const SITE_LOGO = 'site_logo';
    const SITE_ICON = 'site_icon';

    const REGISTRATION_OPEN = 'registration_open';
    const POSTS_PER_PAGE = 'posts_per_page';

    #[Column(type: Column::TYPE_STRING, size: 100, primaryKey: true, notNull: true)]
    public string $name = '';

    #[Column(type: Column::TYPE_STRING)]
    public ?string $value = null;

    #[Column(type: Column::TYPE_DATETIME)]
    public ?string $updated_at = null;
}
