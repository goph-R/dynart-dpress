<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\TaxonomyService;

/**
 * Categories and tags
 *
 * Two lists in one controller because they are one idea to an editor, even though they are two
 * entities to the database - a category has a parent and a position, a tag has neither, and that
 * difference is exactly why they were never one table.
 */
class TaxonomyAdminController extends AbstractAdminController {

    const TAG_SORTABLE = ['id', 'name', 'slug'];

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        FormFactory $forms,
        ListRequest $list,
        protected TaxonomyService $taxonomy,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth, $forms, $list);
    }

    protected function section(): string {
        return 'taxonomy';
    }

    // --- categories ---

    /**
     * The category tree, whole, in the order it renders in
     *
     * **Not a dynamic list**, which it was until 0.29.0. Categories are a tree somebody arranges,
     * and a dynamic list is a table somebody searches - dragging a row means nothing while the
     * rows are sorted by name or split across pages, so the two cannot both be true of one screen.
     * What it cost: the search box, the sortable columns, the pager and *Delete selected*. What it
     * bought: the indent is real, and the order on the screen is the order on the site.
     *
     * The whole tree renders, because a page of a tree is not a tree.
     */
    #[Route('GET', '/admin/categories')]
    public function categories(): string {
        $this->requirePermission(Permissions::CATEGORY_VIEW);
        $canEdit = $this->can(Permissions::CATEGORY_UPDATE);
        // no edit action, for the reason the menu items screen has none: the name opens the
        // category, so an icon beside it is a second button for the same thing
        $rowActions = [];
        if ($this->can(Permissions::CATEGORY_DELETE)) {
            $rowActions[] = ['title' => 'Delete', 'class' => 'delete', 'post' => 'delete_url',
                             'icon' => $this->icon('delete'),
                             'confirm' => 'Delete this category? Its children move up one level, and '
                                 .'the posts in it keep their other categories.'];
        }
        return $this->admin('dpress_admin:taxonomy/categories', [
            'title'      => 'Categories',
            'categories' => $this->categoryTree(),
            'columns'    => [
                // the id, for the reason the content list carries one: `category#21` in somebody's
                // markdown is written by hand as often as it is inserted
                'id'   => ['label' => '#', 'align' => 'right', 'width' => '1%'],
                'name' => ['label' => 'Name', 'tree' => true, 'link' => $canEdit ? 'edit_url' : ''],
                'slug' => ['label' => 'Slug'],
            ],
            'row_actions' => $rowActions,
            'can_create' => $this->can(Permissions::CATEGORY_CREATE),
            'new_url'    => $this->router->url('/admin/categories/new'),
            'tags_url'   => $this->router->url('/admin/tags'),
            // no drag for somebody who may not change one; the endpoint checks the same thing
            'move_url'   => $canEdit ? $this->router->url('/admin/categories/move/') : '',
            'drag_icon'  => $this->icon('drag'),
        ]);
    }

    /**
     * Every category, flattened depth first with the depth kept for the indent
     */
    protected function categoryTree(): array {
        // no `max` in the context: `applyListOptions()` reads it with `isset`, so `max => 0` is
        // `limit 0` and returns nothing, which is not the same as no limit at all
        return $this->flattenCategories($this->taxonomy->categories(), null, 0);
    }

    protected function flattenCategories(array $rows, ?int $parentId, int $depth): array {
        $result = [];
        foreach ($rows as $row) {
            $rowParent = $row['parent_id'] === null ? null : (int)$row['parent_id'];
            if ($rowParent !== $parentId) {
                continue;
            }
            $result[] = [
                'id'        => (int)$row['id'],
                'name'      => (string)$row['name'],
                'slug'      => (string)$row['slug'],
                'parent_id' => $rowParent,
                'depth'     => $depth,
                'edit_url'  => $this->router->url('/admin/categories/edit/'.$row['id']),
                'delete_url' => $this->router->url('/admin/categories/delete/'.$row['id']),
            ];
            foreach ($this->flattenCategories($rows, (int)$row['id'], $depth + 1) as $child) {
                $result[] = $child;
            }
        }
        return $result;
    }

    /**
     * Where a drag on the categories screen lands
     *
     * Answers with data rather than redirecting, for the reason the menu items one does: the
     * screen has already moved the row.
     */
    #[Route('POST', '/admin/categories/move/?')]
    public function moveCategory(string $id): array {
        $this->requirePermission(Permissions::CATEGORY_UPDATE);
        $this->requireAction();
        $category = $this->found($this->taxonomy->findCategory((int)$id));
        $parent = trim((string)$this->request->get('parent_id', ''));
        try {
            $this->taxonomy->moveCategory(
                $category,
                $parent === '' ? null : (int)$parent,
                (int)$this->request->get('position', 0)
            );
        } catch (DpressException $e) {
            return $this->answer(['error' => $e->getMessage()]);
        }
        return $this->answer();
    }

    #[Route('GET', '/admin/categories/new')]
    #[Route('POST', '/admin/categories/new')]
    public function createCategory(): string {
        $this->requirePermission(Permissions::CATEGORY_CREATE);
        $form = $this->forms->create(AdminForms::CATEGORY, ['categories' => $this->parentOptions(null)]);
        if ($form->process()) {
            $form->handle(function ($form) {
                $values = $form->values();
                return $this->taxonomy->createCategory((string)$values['name'], $this->categoryData($values));
            });
            $this->done('/admin/categories', 'Created.');
        }
        return $this->categoryEditor($form, null);
    }

    #[Route('GET', '/admin/categories/edit/?')]
    #[Route('POST', '/admin/categories/edit/?')]
    public function editCategory(string $id): string {
        $this->requirePermission(Permissions::CATEGORY_UPDATE);
        $category = $this->found($this->taxonomy->findCategory((int)$id));
        $form = $this->forms->create(AdminForms::CATEGORY, [
            'category'   => $category,
            'categories' => $this->parentOptions($category->id),
        ]);
        if ($form->process()) {
            $form->handle(fn($form) => $this->taxonomy->updateCategory($category, $this->categoryData($form->values())));
            $this->done('/admin/categories', 'Saved.');
        }
        return $this->categoryEditor($form, $category);
    }

    #[Route('POST', '/admin/categories/delete/?')]
    public function deleteCategory(string $id): string {
        $this->requirePermission(Permissions::CATEGORY_DELETE);
        $this->requireAction();
        $this->taxonomy->deleteCategory($this->found($this->taxonomy->findCategory((int)$id)));
        $this->done('/admin/categories', 'Deleted.');
        return '';
    }

    protected function categoryEditor($form, $category): string {
        return $this->admin('dpress_admin:taxonomy/edit', [
            'title'    => $category === null ? 'New category' : 'Edit category',
            'form'     => $form,
            'back_url' => $this->router->url('/admin/categories'),
        ]);
    }

    /**
     * `name` is a positional argument of `createCategory()`, the rest is the data array
     */
    protected function categoryData(array $values): array {
        return [
            'name'        => $values['name'] ?? '',
            'slug'        => $values['slug'] ?? '',
            'parent_id'   => ($values['parent_id'] ?? '') === '' ? null : (int)$values['parent_id'],
            'description' => $values['description'] ?? '',
            'position'    => (int)($values['position'] ?? 0),
        ];
    }

    /**
     * Every category except the one being edited
     */
    protected function parentOptions(?int $exceptId): array {
        $options = ['' => '(none)'];
        foreach ($this->taxonomy->categories() as $category) {
            if ($exceptId !== null && (int)$category['id'] === $exceptId) {
                continue;
            }
            $options[$category['id']] = $category['name'];
        }
        return $options;
    }

    // --- tags ---

    #[Route('GET', '/admin/tags')]
    public function tags(): string {
        $this->requirePermission(Permissions::TAG_VIEW);
        $config = [
            'endpoint' => $this->router->url('/admin/tags/list'),
            'orderBy'  => 'name',
            'columns'  => [
                'id'   => ['label' => '#', 'align' => 'right', 'width' => '1%'],
                'name' => ['label' => 'Name', 'view' => 'link', 'options' => ['hrefProperty' => 'edit_url']],
                'slug' => ['label' => 'Slug'],
            ],
            'rowActions'   => $this->deleteRowAction('/admin/tags/delete/', Permissions::TAG_DELETE,
                'Delete this tag? It is removed from every post that carries it.'),
            'groupActions' => [],
        ];
        $config['firstPage'] = $this->tagPage($this->firstPageContext($config, self::TAG_SORTABLE, ['search']));
        return $this->admin('dpress_admin:taxonomy/tags', [
            'title'      => 'Tags',
            'can_create' => $this->can(Permissions::TAG_CREATE),
            'new_url'    => $this->router->url('/admin/tags/new'),
            'categories_url' => $this->router->url('/admin/categories'),
            'list_id'    => 'tag-list',
            'list_config' => $config,
        ]);
    }

    #[Route('GET', '/admin/tags/list')]
    public function tagRows(): array {
        $this->requirePermission(Permissions::TAG_VIEW);
        return $this->tagPage($this->list->context(self::TAG_SORTABLE, ['search']));
    }

    /**
     * One page of tags, for the endpoint and for the screen that seeds its first page
     */
    protected function tagPage(array $context): array {
        $rows = [];
        foreach ($this->taxonomy->tags($context) as $tag) {
            $rows[] = [
                'id'       => (int)$tag['id'],
                'name'     => $tag['name'],
                'slug'     => $tag['slug'],
                'edit_url' => $this->can(Permissions::TAG_UPDATE)
                    ? $this->router->url('/admin/tags/edit/'.$tag['id']) : '',
            ];
        }
        return $this->rows($rows, $this->taxonomy->countTags($context));
    }

    #[Route('GET', '/admin/tags/new')]
    #[Route('POST', '/admin/tags/new')]
    public function createTag(): string {
        $this->requirePermission(Permissions::TAG_CREATE);
        $form = $this->forms->create(AdminForms::TAG);
        if ($form->process()) {
            $form->handle(fn($form) => $this->taxonomy->createTag(
                (string)$form->values()['name'], (string)($form->values()['slug'] ?? '')
            ));
            $this->done('/admin/tags', 'Created.');
        }
        return $this->tagEditor($form, null);
    }

    #[Route('GET', '/admin/tags/edit/?')]
    #[Route('POST', '/admin/tags/edit/?')]
    public function editTag(string $id): string {
        $this->requirePermission(Permissions::TAG_UPDATE);
        $tag = $this->found($this->taxonomy->findTag((int)$id));
        $form = $this->forms->create(AdminForms::TAG, ['tag' => $tag]);
        if ($form->process()) {
            $form->handle(fn($form) => $this->taxonomy->updateTag($tag, $form->values()));
            $this->done('/admin/tags', 'Saved.');
        }
        return $this->tagEditor($form, $tag);
    }

    #[Route('POST', '/admin/tags/delete/?')]
    public function deleteTag(string $id): string {
        $this->requirePermission(Permissions::TAG_DELETE);
        $this->requireAction();
        $this->taxonomy->deleteTag($this->found($this->taxonomy->findTag((int)$id)));
        $this->done('/admin/tags', 'Deleted.');
        return '';
    }

    protected function tagEditor($form, $tag): string {
        return $this->admin('dpress_admin:taxonomy/edit', [
            'title'    => $tag === null ? 'New tag' : 'Edit tag',
            'form'     => $form,
            'back_url' => $this->router->url('/admin/tags'),
        ]);
    }

    /**
     * The edit and delete actions both lists share
     */
}
