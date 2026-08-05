<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Menu;
use Dynart\Dpress\Entity\MenuItem;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\MenuService;
use Dynart\Dpress\Service\TaxonomyService;
use Dynart\Dpress\Theme\ThemeService;

/**
 * Menus and their items
 *
 * An item names a target, never a URL, so the editor offers *things* - a page, a category, a tag
 * - and the URL is worked out at render time. That is why renaming a page moves its menu entry
 * with it, and why an item pointing at something deleted disappears from the site but is still
 * listed here, where somebody can fix it.
 */
class MenuAdminController extends AbstractAdminController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        FormFactory $forms,
        ListRequest $list,
        protected MenuService $menus,
        protected ContentService $content,
        protected TaxonomyService $taxonomy,
        protected ThemeService $themes,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth, $forms, $list);
    }

    protected function section(): string {
        return 'menus';
    }

    #[Route('GET', '/admin/menus')]
    public function index(): string {
        $this->requirePermission(Permissions::MENU_VIEW);
        return $this->admin('dpress:admin/menu/list', [
            'title'      => 'Menus',
            'can_edit'   => $this->can(Permissions::MENU_UPDATE),
            'new_url'    => $this->router->url('/admin/menus/new'),
            'places'     => $this->themes->places(),
            'list_id'    => 'menu-list',
            'list_config' => [
                'endpoint' => $this->router->url('/admin/menus/list'),
                'orderBy'  => 'name',
                'columns'  => [
                    'name'  => ['label' => 'Menu', 'view' => 'link', 'options' => ['hrefProperty' => 'items_url']],
                    'place' => ['label' => 'Place', 'sortable' => false],
                    'items' => ['label' => 'Items', 'align' => 'right', 'sortable' => false],
                ],
                'rowActions' => $this->menuRowActions(),
                // as with the roles: every menu there is fits on one page, so the page brings it
                'firstPage' => $this->page(),
            ],
        ]);
    }

    #[Route('GET', '/admin/menus/list')]
    public function rowsJson(): array {
        $this->requirePermission(Permissions::MENU_VIEW);
        return $this->page();
    }

    protected function page(): array {
        $places = $this->themes->places();
        $rows = [];
        foreach ($this->menus->menus() as $menu) {
            $place = (string)$menu['place'];
            $rows[] = [
                'id'    => (int)$menu['id'],
                'name'  => $menu['name'],
                'place' => $place === '' ? '' : ($places[$place] ?? $place.' (not in this theme)'),
                'items' => count($this->menus->itemRows((int)$menu['id'])),
                'items_url' => $this->router->url('/admin/menus/items/'.$menu['id']),
                'edit_url'  => $this->router->url('/admin/menus/edit/'.$menu['id']),
            ];
        }
        return $this->rows($rows, count($rows));
    }

    protected function menuRowActions(): array {
        if (!$this->can(Permissions::MENU_UPDATE)) {
            return [];
        }
        return [
            // "Edit" rather than "Items", because a menu's items are what there is to edit about
            // it - and every other list's first action is Edit. Renaming it is the smaller thing,
            // and gets the text cursor rather than a second pencil.
            ['type' => 'edit', 'title' => 'Edit', 'icon' => $this->icon('edit'),
             'link' => $this->router->url('/admin/menus/items/')],
            ['type' => 'rename', 'title' => 'Rename', 'icon' => $this->icon('rename'),
             'link' => $this->router->url('/admin/menus/edit/')],
            ['type' => 'delete', 'title' => 'Delete', 'icon' => $this->icon('delete'),
             'post' => $this->router->url('/admin/menus/delete/'),
             'confirm' => 'Delete this menu and all of its items?'],
        ];
    }

    // --- menus ---

    #[Route('GET', '/admin/menus/new')]
    #[Route('POST', '/admin/menus/new')]
    public function create(): string {
        $this->requirePermission(Permissions::MENU_UPDATE);
        $form = $this->forms->create(AdminForms::MENU, ['places' => $this->placeOptions()]);
        if ($form->process()) {
            $form->handle(function ($form) {
                $values = $form->values();
                $menu = $this->menus->createMenu((string)$values['name']);
                if (($values['place'] ?? '') !== '') {
                    $this->menus->setPlace($menu, (string)$values['place']);
                }
                return $menu;
            });
            $this->done('/admin/menus', 'Created.');
        }
        return $this->menuEditor($form, null);
    }

    #[Route('GET', '/admin/menus/edit/?')]
    #[Route('POST', '/admin/menus/edit/?')]
    public function edit(string $id): string {
        $this->requirePermission(Permissions::MENU_UPDATE);
        $menu = $this->found($this->menus->findMenu((int)$id));
        $form = $this->forms->create(AdminForms::MENU, ['menu' => $menu, 'places' => $this->placeOptions()]);
        if ($form->process()) {
            $form->handle(function ($form) use ($menu) {
                $values = $form->values();
                $menu->name = trim((string)$values['name']);
                $this->menus->setPlace($menu, (string)($values['place'] ?? ''));
                return $menu;
            });
            $this->done('/admin/menus', 'Saved.');
        }
        return $this->menuEditor($form, $menu);
    }

    #[Route('POST', '/admin/menus/delete/?')]
    public function delete(string $id): string {
        $this->requirePermission(Permissions::MENU_UPDATE);
        $this->requireAction();
        $this->menus->deleteMenu($this->found($this->menus->findMenu((int)$id)));
        $this->done('/admin/menus', 'Deleted.');
        return '';
    }

    protected function menuEditor($form, ?Menu $menu): string {
        return $this->admin('dpress:admin/menu/edit', [
            'title'    => $menu === null ? 'New menu' : 'Edit menu',
            'form'     => $form,
            'menu'     => $menu,
            'narrow'   => true,
            'back_url' => $this->router->url('/admin/menus'),
        ]);
    }

    /**
     * @return array [place => label], with the empty one first
     */
    protected function placeOptions(): array {
        return ['' => '(not placed)'] + $this->themes->places();
    }

    // --- items ---

    #[Route('GET', '/admin/menus/items/?')]
    public function items(string $id): string {
        $this->requirePermission(Permissions::MENU_VIEW);
        $menu = $this->found($this->menus->findMenu((int)$id));
        return $this->admin('dpress:admin/menu/items', [
            'title'    => $menu->name,
            'menu'     => $menu,
            'items'    => $this->itemRows($menu),
            'can_edit' => $this->can(Permissions::MENU_UPDATE),
            'new_url'  => $this->router->url('/admin/menus/items/'.$menu->id.'/new'),
            'back_url' => $this->router->url('/admin/menus'),
        ]);
    }

    /**
     * The items of one menu, flattened with a depth for the indent
     *
     * Rendered by the server rather than by a dynamic list: a menu is a tree somebody is
     * rearranging, not a table somebody is searching, and the two need different things.
     */
    protected function itemRows(Menu $menu): array {
        $rows = $this->menus->itemRows($menu->id);
        return $this->flatten($rows, null, 0);
    }

    protected function flatten(array $rows, ?int $parentId, int $depth): array {
        $result = [];
        foreach ($rows as $row) {
            $rowParent = $row['parent_id'] === null ? null : (int)$row['parent_id'];
            if ($rowParent !== $parentId) {
                continue;
            }
            $result[] = [
                'id'       => (int)$row['id'],
                'label'    => (string)$row['label'],
                'target'   => $this->describeTarget($row),
                'url'      => $this->menus->resolveUrl($row),
                'position' => (int)$row['position'],
                'depth'    => $depth,
                'edit_url' => $this->router->url('/admin/menus/items/edit/'.$row['id']),
                'delete_url' => $this->router->url('/admin/menus/items/delete/'.$row['id']),
            ];
            foreach ($this->flatten($rows, (int)$row['id'], $depth + 1) as $child) {
                $result[] = $child;
            }
        }
        return $result;
    }

    /**
     * What an item points at, in words
     */
    protected function describeTarget(array $row): string {
        $id = $row['target_id'] === null ? null : (int)$row['target_id'];
        switch ($row['target_type']) {
            case MenuItem::TARGET_HOME:
                return 'The front page';
            case MenuItem::TARGET_URL:
                return (string)$row['url'];
            case MenuItem::TARGET_CONTENT:
                $content = $id === null ? null : $this->content->findById($id);
                return $content === null ? 'A deleted item' : $content->title;
            case MenuItem::TARGET_CATEGORY:
                $category = $id === null ? null : $this->taxonomy->findCategory($id);
                return $category === null ? 'A deleted category' : 'Category: '.$category->name;
            case MenuItem::TARGET_TAG:
                $tag = $id === null ? null : $this->taxonomy->findTag($id);
                return $tag === null ? 'A deleted tag' : 'Tag: '.$tag->name;
        }
        return '';
    }

    #[Route('GET', '/admin/menus/items/?/new')]
    #[Route('POST', '/admin/menus/items/?/new')]
    public function createItem(string $id): string {
        $this->requirePermission(Permissions::MENU_UPDATE);
        $menu = $this->found($this->menus->findMenu((int)$id));
        $form = $this->forms->create(AdminForms::MENU_ITEM, $this->itemContext($menu, null));
        if ($form->process()) {
            $form->handle(fn($form) => $this->menus->addItem($menu, $this->itemData($form->values())));
            $this->done('/admin/menus/items/'.$menu->id, 'Added.');
        }
        return $this->itemEditor($menu, $form, null);
    }

    #[Route('GET', '/admin/menus/items/edit/?')]
    #[Route('POST', '/admin/menus/items/edit/?')]
    public function editItem(string $id): string {
        $this->requirePermission(Permissions::MENU_UPDATE);
        $item = $this->found($this->menus->findItem((int)$id));
        $menu = $this->found($this->menus->findMenu($item->menu_id));
        $form = $this->forms->create(AdminForms::MENU_ITEM, $this->itemContext($menu, $item));
        if ($form->process()) {
            $form->handle(fn($form) => $this->menus->updateItem($item, $this->itemData($form->values())));
            $this->done('/admin/menus/items/'.$menu->id, 'Saved.');
        }
        return $this->itemEditor($menu, $form, $item);
    }

    #[Route('POST', '/admin/menus/items/delete/?')]
    public function deleteItem(string $id): string {
        $this->requirePermission(Permissions::MENU_UPDATE);
        $this->requireAction();
        $item = $this->found($this->menus->findItem((int)$id));
        $menuId = $item->menu_id;
        $this->menus->deleteItem($item);
        $this->done('/admin/menus/items/'.$menuId, 'Deleted.');
        return '';
    }

    protected function itemEditor(Menu $menu, $form, ?MenuItem $item): string {
        return $this->admin('dpress:admin/menu/item-edit', [
            'title'    => $item === null ? 'New menu item' : 'Edit menu item',
            'form'     => $form,
            'menu'     => $menu,
            'narrow'   => true,
            'back_url' => $this->router->url('/admin/menus/items/'.$menu->id),
        ]);
    }

    /**
     * Everything an item could point at, in one flat list
     *
     * One `target` select rather than one per type: the editor picks a kind and then a thing, and
     * the browser has all of them already, so switching the kind costs no request.
     */
    protected function itemContext(Menu $menu, ?MenuItem $item): array {
        $targets = ['' => '(none)'];
        foreach ($this->content->findAll(['max' => 500]) as $content) {
            $targets[$content['id']] = ucfirst($content['type']).': '.$content['title'];
        }
        foreach ($this->taxonomy->categories() as $category) {
            $targets['c'.$category['id']] = 'Category: '.$category['name'];
        }
        foreach ($this->taxonomy->tags() as $tag) {
            $targets['t'.$tag['id']] = 'Tag: '.$tag['name'];
        }
        $items = ['' => '(top level)'];
        foreach ($this->menus->itemRows($menu->id) as $row) {
            if ($item !== null && (int)$row['id'] === $item->id) {
                continue; // an item cannot be its own parent
            }
            $items[$row['id']] = $row['label'];
        }
        return ['menu' => $menu, 'item' => $item, 'targets' => $targets, 'items' => $items];
    }

    /**
     * The target select carries the kind in its value, so it is split back out here
     */
    protected function itemData(array $values): array {
        $type = (string)($values['target_type'] ?? MenuItem::TARGET_CONTENT);
        $target = (string)($values['target_id'] ?? '');
        $targetId = null;
        if ($target !== '') {
            $targetId = (int)ltrim($target, 'ct');
        }
        return [
            'label'       => (string)($values['label'] ?? ''),
            'target_type' => in_array($type, MenuItem::TARGETS, true) ? $type : MenuItem::TARGET_CONTENT,
            'target_id'   => $type === MenuItem::TARGET_URL || $type === MenuItem::TARGET_HOME ? null : $targetId,
            'url'         => (string)($values['url'] ?? ''),
            'parent_id'   => ($values['parent_id'] ?? '') === '' ? null : (int)$values['parent_id'],
            'position'    => (int)($values['position'] ?? 0),
        ];
    }
}
