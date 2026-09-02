<?php

namespace Dynart\Dpress\Block;

use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Entity\Block;
use Dynart\Dpress\Service\TaxonomyService;

/**
 * The categories, nested the way they are nested
 *
 * A category is a tree - that is the structural difference from a tag, and the reason the two are
 * separate entities rather than one with a `type` column - so the block is a tree too. The rows
 * come back flat in position order and are nested here; the template recurses.
 *
 * What it hands the template is `name`, `slug` and `children`, not URLs: a template builds those
 * with `route_url()`, the same as every other front-end link, so nothing here has to know whether
 * the site sits in a subfolder.
 */
class CategoryListBlock {

    public function __construct(protected TaxonomyService $taxonomy, protected ViewInterface $view) {}

    public function render(Block $block, array $settings): string {
        $tree = $this->tree($this->taxonomy->categories());
        return $tree === [] ? '' : $this->view->fetch('dpress:block/category-list', ['items' => $tree]);
    }

    /**
     * Flat rows into nested ones
     *
     * A category whose parent is not in the list - which the admin does not allow, but a
     * hand-edited row can be - is kept at the top level rather than dropped, on the same terms as
     * a menu item whose target has gone: visible and fixable beats silently absent.
     */
    protected function tree(array $categories): array {
        $ids = [];
        foreach ($categories as $category) {
            $ids[(int)$category['id']] = true;
        }
        $children = [];
        foreach ($categories as $category) {
            $parent = (int)($category['parent_id'] ?? 0);
            $children[isset($ids[$parent]) ? $parent : 0][] = $category;
        }
        return $this->branch($children, 0);
    }

    protected function branch(array $children, int $parentId): array {
        $out = [];
        foreach ($children[$parentId] ?? [] as $category) {
            $out[] = [
                'name'     => (string)$category['name'],
                'slug'     => (string)$category['slug'],
                'children' => $this->branch($children, (int)$category['id']),
            ];
        }
        return $out;
    }
}
