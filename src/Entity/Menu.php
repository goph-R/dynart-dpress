<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * A named menu, attached to a place in the layout
 *
 * A theme declares the places it renders - `main`, `footer` - and a menu is assigned to one.
 * Swapping themes therefore leaves menus pointing at places the new theme may not have, which
 * is fine: an unrendered menu is invisible, not broken.
 *
 * Deliberately **not audited** (plan §4.4): a menu editor rewrites the tree wholesale, so the
 * history would record churn rather than meaning.
 */
#[Table(name: 'menu')]
class Menu extends Entity {

    protected static string $eventName = 'menu';

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    #[Column(type: Column::TYPE_STRING, size: 100, notNull: true)]
    public string $name = '';

    /** Which place in the layout it renders in; empty means it is not rendered anywhere */
    #[Column(type: Column::TYPE_STRING, size: 50, notNull: true, index: true, default: '')]
    public string $place = '';
}
