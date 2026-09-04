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

    /** The kinds that point at a row in this site, and so have something to choose in the editor */
    const TARGETS_WITH_ID = [self::TARGET_CONTENT, self::TARGET_CATEGORY, self::TARGET_TAG];

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

    /**
     * The two target columns as one value, for the select that chooses between them
     *
     * The editor asks the kind in one select and the thing in another, so the second one's value
     * has to carry its own kind: two fields can disagree, and they did - silently - until 0.41.0.
     * It says so in the same words `target_type` uses, `content:12`, which is what lets the
     * browser narrow the list to the kind that was chosen without a second vocabulary to keep in
     * step with this one.
     */
    public static function targetValue(string $type, ?int $id): string {
        return $id === null || !in_array($type, self::TARGETS_WITH_ID, true) ? '' : $type.':'.$id;
    }

    /**
     * The id back out of that value, but only when its kind is the kind that was chosen
     *
     * A value of the wrong kind is **no target at all** rather than an id: `ltrim($value, 'ct')`
     * used to strip the prefix whatever the kind was, so a tag picked under *A category* pointed
     * the item at the category with that id, at a URL nobody had chosen. Refusing it here is what
     * lets `itemProblem()` say so out loud.
     */
    public static function targetIdIn(string $type, string $value): ?int {
        $prefix = $type.':';
        if (!in_array($type, self::TARGETS_WITH_ID, true) || !str_starts_with($value, $prefix)) {
            return null;
        }
        $id = substr($value, strlen($prefix));
        return ctype_digit($id) && (int)$id > 0 ? (int)$id : null;
    }
}
