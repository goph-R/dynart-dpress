<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * One thing in a place beside the content - a tag cloud, a category list, a piece of markdown
 *
 * A block is a **type plus its settings**, not a table of columns per type. `type` names something
 * registered in `Blocks`, and `settings` holds whatever that type asked for: a limit for the tag
 * cloud, the markdown for a custom block. The alternative - a column per type - means a schema
 * change every time somebody registers a new kind, which a plugin cannot do.
 *
 * `place` is the same vocabulary a menu uses, so a theme declares `places[] = sidebar` once and
 * both the menu editor and this offer it. An unplaced block, or one in a place the active theme
 * does not render, is invisible rather than broken - exactly what happens to a menu.
 *
 * Deliberately **not audited**, like `Menu` and `MenuItem` (plan §4.4): arranging a layout is
 * moving things about, and a revision per drag would be churn rather than history.
 */
#[Table(name: 'block')]
class Block extends Entity {

    protected static string $eventName = 'block';

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    /** A name registered in `Blocks`; one that is not renders nothing */
    #[Column(type: Column::TYPE_STRING, size: 50, notNull: true)]
    public string $type = '';

    /** Which place in the layout it renders in; empty means it is not rendered anywhere */
    #[Column(type: Column::TYPE_STRING, size: 50, notNull: true, index: true, default: '')]
    public string $place = '';

    /** The heading above it. Empty means no heading, which is what a Ko-fi button wants */
    #[Column(type: Column::TYPE_STRING, size: 100, notNull: true, default: '')]
    public string $title = '';

    #[Column(type: Column::TYPE_INT, notNull: true, default: 0)]
    public int $position = 0;

    #[Column(type: Column::TYPE_BOOL, notNull: true, default: true)]
    public bool $enabled = true;

    /**
     * The type's own settings, as JSON
     *
     * Read and written through `settings()` / `setSettings()` rather than directly, so a row that
     * predates a type's field - or one somebody edited by hand - is an empty array rather than a
     * fatal in a template.
     */
    #[Column(type: Column::TYPE_STRING)]
    public ?string $settings = null;

    public function settings(): array {
        $value = json_decode((string)$this->settings, true);
        return is_array($value) ? $value : [];
    }

    public function setSettings(array $settings): void {
        $this->settings = json_encode($settings);
    }

    /** One setting, with a default for the ones a type added after this row was saved */
    public function setting(string $name, $default = null) {
        return $this->settings()[$name] ?? $default;
    }
}
