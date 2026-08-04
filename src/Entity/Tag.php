<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * A flat taxonomy term
 */
#[Auditable]
#[Table(name: 'tag')]
class Tag extends Entity {

    protected static string $eventName = 'tag';

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    #[Column(type: Column::TYPE_STRING, size: 100, notNull: true)]
    public string $name = '';

    #[Column(type: Column::TYPE_STRING, size: 200, notNull: true, unique: true)]
    public string $slug = '';
}
