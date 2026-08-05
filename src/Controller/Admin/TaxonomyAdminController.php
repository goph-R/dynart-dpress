<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
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

    const CATEGORY_SORTABLE = ['name', 'slug', 'position'];
    const TAG_SORTABLE = ['name', 'slug'];

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

    #[Route('GET', '/admin/categories')]
    public function categories(): string {
        $this->requirePermission(Permissions::CATEGORY_VIEW);
        $config = [
            'endpoint' => $this->router->url('/admin/categories/list'),
            'orderBy'  => 'name',
            'columns'  => [
                'name'      => ['label' => 'Name', 'view' => 'link', 'options' => ['hrefProperty' => 'edit_url']],
                'slug'      => ['label' => 'Slug'],
                'parent'    => ['label' => 'Parent', 'sortable' => false],
                'position'  => ['label' => 'Position', 'align' => 'right'],
            ],
            'rowActions' => $this->rowActions('categories', Permissions::CATEGORY_UPDATE, Permissions::CATEGORY_DELETE,
                'Delete this category? The posts in it keep their other categories.'),
        ];
        $config['firstPage'] = $this->categoryPage($this->firstPageContext($config, self::CATEGORY_SORTABLE, ['search']));
        return $this->admin('dpress:admin/taxonomy/categories', [
            'title'      => 'Categories',
            'can_create' => $this->can(Permissions::CATEGORY_CREATE),
            'new_url'    => $this->router->url('/admin/categories/new'),
            'tags_url'   => $this->router->url('/admin/tags'),
            'list_id'    => 'category-list',
            'list_config' => $config,
        ]);
    }

    #[Route('GET', '/admin/categories/list')]
    public function categoryRows(): array {
        $this->requirePermission(Permissions::CATEGORY_VIEW);
        return $this->categoryPage($this->list->context(self::CATEGORY_SORTABLE, ['search']));
    }

    /**
     * One page of categories, for the endpoint and for the screen that seeds its first page
     */
    protected function categoryPage(array $context): array {
        $names = $this->categoryNames();
        $rows = [];
        foreach ($this->taxonomy->categories($context) as $category) {
            $rows[] = [
                'id'       => (int)$category['id'],
                'name'     => $category['name'],
                'slug'     => $category['slug'],
                'position' => (int)$category['position'],
                'parent'   => $category['parent_id'] === null ? '' : ($names[(int)$category['parent_id']] ?? '?'),
                'edit_url' => $this->router->url('/admin/categories/edit/'.$category['id']),
            ];
        }
        return $this->rows($rows, $this->taxonomy->countCategories($context));
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
        return $this->admin('dpress:admin/taxonomy/edit', [
            'title'    => $category === null ? 'New category' : 'Edit category',
            'form'     => $form,
            'narrow'   => true,
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

    protected function categoryNames(): array {
        $names = [];
        foreach ($this->taxonomy->categories() as $category) {
            $names[(int)$category['id']] = $category['name'];
        }
        return $names;
    }

    // --- tags ---

    #[Route('GET', '/admin/tags')]
    public function tags(): string {
        $this->requirePermission(Permissions::TAG_VIEW);
        $config = [
            'endpoint' => $this->router->url('/admin/tags/list'),
            'orderBy'  => 'name',
            'columns'  => [
                'name' => ['label' => 'Name', 'view' => 'link', 'options' => ['hrefProperty' => 'edit_url']],
                'slug' => ['label' => 'Slug'],
            ],
            'rowActions' => $this->rowActions('tags', Permissions::TAG_UPDATE, Permissions::TAG_DELETE,
                'Delete this tag? It is removed from every post that carries it.'),
        ];
        $config['firstPage'] = $this->tagPage($this->firstPageContext($config, self::TAG_SORTABLE, ['search']));
        return $this->admin('dpress:admin/taxonomy/tags', [
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
                'edit_url' => $this->router->url('/admin/tags/edit/'.$tag['id']),
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
        return $this->admin('dpress:admin/taxonomy/edit', [
            'title'    => $tag === null ? 'New tag' : 'Edit tag',
            'form'     => $form,
            'narrow'   => true,
            'back_url' => $this->router->url('/admin/tags'),
        ]);
    }

    /**
     * The edit and delete actions both lists share
     */
    protected function rowActions(string $segment, string $update, string $delete, string $confirm): array {
        $actions = [];
        if ($this->can($update)) {
            $actions[] = ['type' => 'edit', 'title' => 'Edit', 'icon' => $this->icon('edit'),
                          'link' => $this->router->url('/admin/'.$segment.'/edit/')];
        }
        if ($this->can($delete)) {
            $actions[] = ['type' => 'delete', 'title' => 'Delete', 'icon' => $this->icon('delete'),
                          'post' => $this->router->url('/admin/'.$segment.'/delete/'),
                          'confirm' => $confirm];
        }
        return $actions;
    }
}
