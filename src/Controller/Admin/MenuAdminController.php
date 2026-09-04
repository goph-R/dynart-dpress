<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\DpressException;
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
        return $this->admin('dpress_admin:menu/list', [
            'title'      => 'Menus',
            'can_edit'   => $this->can(Permissions::MENU_UPDATE),
            'new_url'    => $this->router->url('/admin/menus/new'),
            'places'     => $this->themes->places(),
            'list_id'    => 'menu-list',
            'list_config' => [
                'endpoint' => $this->router->url('/admin/menus/list'),
                'orderBy'  => 'name',
                'columns'  => [
                    // not sortable, for the same reason as the roles: `page()` takes no sort
                    'id'    => ['label' => '#', 'align' => 'right', 'width' => '1%', 'sortable' => false],
                    'name'  => ['label' => 'Menu', 'view' => 'link', 'options' => ['hrefProperty' => 'items_url']],
                    'place' => ['label' => 'Place', 'sortable' => false],
                    'items' => ['label' => 'Items', 'align' => 'right', 'sortable' => false],
                ],
                'rowActions'   => $this->menuRowActions(),
                'groupActions' => [],
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

    /**
     * Rename is the only one left, and it is here because the name cell is already spoken for
     *
     * A menu's items are what there is to edit about it, so the name opens those - which leaves
     * renaming with no cell to hang off. Every other list gets that for free from its name
     * column; this one has to keep a button.
     */
    protected function menuRowActions(): array {
        if (!$this->can(Permissions::MENU_UPDATE)) {
            return [];
        }
        return array_merge(
            [['type' => 'rename', 'title' => 'Rename', 'icon' => $this->icon('rename'),
              'link' => $this->router->url('/admin/menus/edit/')]],
            $this->deleteRowAction('/admin/menus/delete/', Permissions::MENU_UPDATE,
                'Delete this menu and all of its items?')
        );
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
        return $this->admin('dpress_admin:menu/edit', [
            'title'    => $menu === null ? 'New menu' : 'Edit menu',
            'form'     => $form,
            'menu'     => $menu,
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
        $canEdit = $this->can(Permissions::MENU_UPDATE);
        // no edit action: the label opens the item, so an icon beside it is a second button for
        // the same thing - the rule the lists have followed since the row actions went
        $rowActions = [];
        if ($canEdit) {
            $rowActions[] = ['title' => 'Delete', 'class' => 'delete', 'post' => 'delete_url',
                             'icon' => $this->icon('delete'),
                             'confirm' => 'Delete this item? Its children move up one level.'];
        }
        return $this->admin('dpress_admin:menu/items', [
            'title'    => $menu->name,
            'menu'     => $menu,
            'items'    => $this->itemRows($menu),
            'columns'  => [
                'label'       => ['label' => 'Label', 'tree' => true, 'link' => $canEdit ? 'edit_url' : ''],
                // markup, because a target that has gone says so under itself - the same opt out
                // a dynamic list's `html` view is, and for the same reason
                'target_html' => ['label' => 'Points at', 'view' => 'html'],
            ],
            'row_actions' => $rowActions,
            'can_edit' => $canEdit,
            'new_url'  => $this->router->url('/admin/menus/items/'.$menu->id.'/new'),
            'back_url' => $this->router->url('/admin/menus'),
            'move_url' => $canEdit ? $this->router->url('/admin/menus/items/move/') : '',
            'drag_icon' => $this->icon('drag'),
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
            $url = $this->menus->resolveUrl($row);
            $result[] = [
                'id'       => (int)$row['id'],
                'label'    => (string)$row['label'],
                'target'   => $this->describeTarget($row),
                // Built here rather than in the template, because it is markup rather than a value
                // and the tree partial escapes anything it is not told is markup. `htmlspecialchars`
                // rather than `esc_html`, which is a *view* helper and is not loaded out here - the
                // two are the same call, and this is the side of the fence that has to say so.
                'target_html' => htmlspecialchars($this->describeTarget($row))
                    .($url === null
                        ? '<small class="form-error">not rendered - '.$this->whyNotRendered($row).'</small>'
                        : ''),
                // what makes the row stand out: the item is in the menu and renders nowhere
                'class'    => $url === null ? 'broken' : '',
                'url'      => $url,
                'position' => (int)$row['position'],
                'parent_id' => $rowParent,
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
            $data = $this->itemData($form->values());
            $problem = $this->itemProblem($data);
            if ($problem !== null) {
                $form->addError($problem);
            } else {
                $form->handle(fn($form) => $this->menus->addItem($menu, $data));
                $this->done('/admin/menus/items/'.$menu->id, 'Added.');
            }
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
            $data = $this->itemData($form->values());
            $problem = $this->itemProblem($data);
            if ($problem !== null) {
                $form->addError($problem);
            } else {
                $form->handle(fn($form) => $this->menus->updateItem($item, $data));
                $this->done('/admin/menus/items/'.$menu->id, 'Saved.');
            }
        }
        return $this->itemEditor($menu, $form, $item);
    }

    /**
     * Where a drag on the items screen lands
     *
     * Answers with data rather than redirecting: the screen has already moved the row, and
     * reloading it would throw away the scroll position for a change the person can see.
     *
     * `parent_id` empty means the top level, which is why it is read as a string first - `(int)''`
     * is `0`, and `0` is not a parent, it is "no parent" spelled in a way `findItem()` would go
     * looking for.
     */
    #[Route('POST', '/admin/menus/items/move/?')]
    public function moveItem(string $id): array {
        $this->requirePermission(Permissions::MENU_UPDATE);
        $this->requireAction();
        $item = $this->found($this->menus->findItem((int)$id));
        $parent = trim((string)$this->request->get('parent_id', ''));
        try {
            $this->menus->moveItem(
                $item,
                $parent === '' ? null : (int)$parent,
                (int)$this->request->get('position', 0)
            );
        } catch (DpressException $e) {
            return $this->answer(['error' => $e->getMessage()]);
        }
        return $this->answer();
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
        return $this->admin('dpress_admin:menu/item-edit', [
            'title'    => $item === null ? 'New menu item' : 'Edit menu item',
            'form'     => $form,
            'menu'     => $menu,
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
        return [
            'label'       => (string)($values['label'] ?? ''),
            'target_type' => in_array($type, MenuItem::TARGETS, true) ? $type : MenuItem::TARGET_CONTENT,
            'target_id'   => $this->targetId($type, (string)($values['target_id'] ?? '')),
            'url'         => (string)($values['url'] ?? ''),
            'parent_id'   => ($values['parent_id'] ?? '') === '' ? null : (int)$values['parent_id'],
            'position'    => (int)($values['position'] ?? 0),
        ];
    }

    /**
     * The id out of a target value, but only if its kind is the kind that was chosen
     *
     * The select carries the kind in the value - `12` is content, `c12` a category, `t12` a tag -
     * and the kind was *also* chosen in "Points at". Two fields can disagree, and they did:
     * `ltrim($target, 'ct')` on a tag under a category type gave `12` and the item then pointed at
     * category 12, silently, at a URL nobody had chosen. So a value whose prefix does not match
     * the type is **no target at all**, which `itemProblem()` then refuses out loud.
     */
    /**
     * Why an item renders nowhere, in the words that say what to do about it
     *
     * *"Its target is gone"* was the only answer, and it is the wrong one for the common case:
     * an item that never had a target reads as one whose post somebody deleted, which sends you
     * looking through the bin for something that was never there.
     */
    protected function whyNotRendered(array $row): string {
        if ($row['target_type'] === MenuItem::TARGET_URL) {
            return 'it has no address';
        }
        return $row['target_id'] === null ? 'nothing is chosen for it to point at' : 'its target is gone';
    }

    protected function targetId(string $type, string $value): ?int {
        $expected = [
            MenuItem::TARGET_CONTENT  => '',
            MenuItem::TARGET_CATEGORY => 'c',
            MenuItem::TARGET_TAG      => 't',
        ];
        if ($value === '' || !array_key_exists($type, $expected)) {
            return null;   // home and an external address point at nothing in the library
        }
        $prefix = $expected[$type];
        if ($prefix !== '' && !str_starts_with($value, $prefix)) {
            return null;
        }
        $id = $prefix === '' ? $value : substr($value, strlen($prefix));
        return ctype_digit($id) ? (int)$id : null;
    }

    /**
     * Why this item could never render, or null when it can
     *
     * **The form lets you describe an item that cannot work**: five kinds in one select, a target
     * in another and an address in a third, and nothing said when they disagree. Leave "Points at"
     * on its default and type an address - which is the obvious way to add an external link - and
     * what got saved was a post link with no post, reported afterwards as *"its target is gone"*.
     * It was never there.
     *
     * Refused at the form rather than thrown from the service, because this is somebody filling in
     * a form wrong and the answer to that is the form again with the reason on it.
     */
    protected function itemProblem(array $data): ?string {
        if ($data['target_type'] === MenuItem::TARGET_URL) {
            return trim((string)$data['url']) === ''
                ? 'An external address needs an address. Fill in Address, or choose something else in Points at.'
                : null;
        }
        if ($data['target_type'] === MenuItem::TARGET_HOME || $data['target_id'] !== null) {
            return null;
        }
        return 'Choose a Target of that kind, or set Points at to "An external address" and fill in Address.';
    }
}
