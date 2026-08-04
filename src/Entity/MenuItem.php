<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * One entry of a menu
 *
 * An item points at something by *type and id* rather than by a stored URL, so renaming a page
 * moves its menu entry with it. An external link is the exception: there is nothing local to
 * point at, so the URL is the target.
 *
 * Not audited, like `Menu`.
 */
#[Table(name: 'menu_item')]
class MenuItem extends Entity {

    protected static string $eventName = 'menu_item';

    const TARGET_CONTENT = 'content';
    const TARGET_CATEGORY = 'category';
    const TARGET_TAG = 'tag';
    const TARGET_URL = 'url';
    const TARGET_HOME = 'home';

    const TARGETS = [
        self::TARGET_CONTENT, self::TARGET_CATEGORY,
        self::TARGET_TAG, self::TARGET_URL, self::TARGET_HOME,
    ];

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    #[Column(type: Column::TYPE_INT, notNull: true, index: true, foreignKey: [Menu::class, 'id'])]
    public int $menu_id = 0;

    /** Menus nest, so a submenu is an item with a parent */
    #[Column(type: Column::TYPE_INT, index: true)]
    public ?int $parent_id = null;

    #[Column(type: Column::TYPE_STRING, size: 100, notNull: true)]
    public string $label = '';

    #[Column(type: Column::TYPE_STRING, size: 20, notNull: true, default: self::TARGET_CONTENT)]
    public string $target_type = self::TARGET_CONTENT;

    /** The id of what it points at, unused for a URL or the home target */
    #[Column(type: Column::TYPE_INT)]
    public ?int $target_id = null;

    /** Only for `url` targets */
    #[Column(type: Column::TYPE_STRING, size: 500)]
    public ?string $url = null;

    #[Column(type: Column::TYPE_INT, notNull: true, default: 0)]
    public int $position = 0;

    public function isExternal(): bool {
        return $this->target_type === self::TARGET_URL;
    }
}
