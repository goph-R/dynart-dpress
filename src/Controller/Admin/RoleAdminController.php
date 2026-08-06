<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\RoleService;
use Dynart\Dpress\Service\UserService;

/**
 * Roles and what they may do
 *
 * The permission editor is generated from `Permissions::grouped()`, which is why a plugin adding
 * `myplugin.do_thing` appears here with nothing else to do - permissions are plain strings and
 * there is no table to migrate.
 *
 * The **admin** role is shown but not editable: it holds everything implicitly, so a checkbox
 * grid over it would be a lie, and it is the role that must survive every mistake made here.
 */
class RoleAdminController extends AbstractAdminController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        FormFactory $forms,
        ListRequest $list,
        protected RoleService $roles,
        protected UserService $users,
        protected Permissions $permissions,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth, $forms, $list);
    }

    protected function section(): string {
        return 'roles';
    }

    #[Route('GET', '/admin/roles')]
    public function index(): string {
        $this->requirePermission(Permissions::ROLE_VIEW);
        return $this->admin('dpress_admin:role/list', [
            'title'      => 'Roles',
            'can_create' => $this->can(Permissions::ROLE_CREATE),
            'new_url'    => $this->router->url('/admin/roles/new'),
            'list_id'    => 'role-list',
            'list_config' => [
                'endpoint' => $this->router->url('/admin/roles/list'),
                'orderBy'  => 'name',
                'columns'  => [
                    'label'       => ['label' => 'Role', 'view' => 'link', 'options' => ['hrefProperty' => 'edit_url']],
                    'name'        => ['label' => 'Name'],
                    'permissions' => ['label' => 'Permissions', 'sortable' => false],
                    'users'       => ['label' => 'Users', 'align' => 'right', 'sortable' => false],
                ],
                'rowActions'   => [],
                'groupActions' => $this->groupActions(),
                // there are as many roles as somebody made: one page, no paging, and the screen
                // may as well arrive with it rather than send the browser back for it
                'firstPage' => $this->page(),
            ],
        ]);
    }

    #[Route('GET', '/admin/roles/list')]
    public function rowsJson(): array {
        $this->requirePermission(Permissions::ROLE_VIEW);
        return $this->page();
    }

    protected function page(): array {
        $rows = [];
        foreach ($this->roles->findAll() as $role) {
            $isAdmin = $role['name'] === Role::NAME_ADMIN;
            $rows[] = [
                'id'          => (int)$role['id'],
                'name'        => $role['name'],
                'label'       => $role['label'] !== '' ? $role['label'] : $role['name'],
                'permissions' => $isAdmin ? 'everything' : (string)count($this->roles->permissionsOf((int)$role['id'])),
                'users'       => $this->users->countByRole($role['name']),
                'removable'   => (bool)$role['removable'] && !$isAdmin,
                'editable'    => !$isAdmin,
                // no link on the administrator role: it is not editable, and the column falls
                // back to plain text rather than offering a door that does not open
                'edit_url'    => $isAdmin || !$this->can(Permissions::ROLE_UPDATE)
                    ? '' : $this->router->url('/admin/roles/edit/'.$role['id']),
            ];
        }
        return $this->rows($rows, count($rows));
    }

    protected function groupActions(): array {
        if (!$this->can(Permissions::ROLE_DELETE)) {
            return [];
        }
        return [['type' => 'delete', 'label' => 'Delete selected',
                 'post' => $this->router->url('/admin/roles/delete-selected'),
                 'confirm' => 'Delete the selected roles? Everybody holding one loses what it granted.']];
    }

    #[Route('GET', '/admin/roles/new')]
    #[Route('POST', '/admin/roles/new')]
    public function create(): string {
        $this->requirePermission(Permissions::ROLE_CREATE);
        $form = $this->forms->create(AdminForms::ROLE, ['permission_groups' => $this->permissions->grouped()]);
        if ($form->process()) {
            try {
                $form->handle(function ($form) {
                    $values = $form->values();
                    return $this->roles->create(
                        (string)$values['name'], (string)$values['label'], $this->knownPermissions($values)
                    );
                });
                $this->done('/admin/roles', 'Created.');
            } catch (DpressException $e) {
                $form->addError($e->getMessage());
            }
        }
        return $this->editor($form, null);
    }

    #[Route('GET', '/admin/roles/edit/?')]
    #[Route('POST', '/admin/roles/edit/?')]
    public function edit(string $id): string {
        $this->requirePermission(Permissions::ROLE_UPDATE);
        $role = $this->found($this->roles->findById((int)$id));
        if ($role->name === Role::NAME_ADMIN) {
            $this->done('/admin/roles', 'The admin role holds every permission and is not edited here.');
        }
        $form = $this->forms->create(AdminForms::ROLE, [
            'role' => $role,
            'permission_groups' => $this->permissions->grouped(),
            'selected_permissions' => $this->roles->permissionsOf($role->id),
        ]);
        if ($form->process()) {
            $form->handle(function ($form) use ($role) {
                $values = $form->values();
                $role->label = trim((string)$values['label']);
                $this->roles->update($role);
                $this->roles->setPermissions($role, $this->knownPermissions($values));
                return $role;
            });
            $this->done('/admin/roles', 'Saved.');
        }
        return $this->editor($form, $role);
    }

    #[Route('POST', '/admin/roles/delete-selected')]
    public function deleteMany(): string {
        $this->requirePermission(Permissions::ROLE_DELETE);
        $this->requireAction();
        $notice = $this->deleteSelected(function (int $id) {
            $role = $this->roles->findById($id);
            if ($role === null) {
                return false;
            }
            // `delete()` refuses the ones the system needs and says which - the selection is a
            // rectangle somebody dragged, and it will contain those
            $this->roles->delete($role);
            return true;
        });
        $this->done('/admin/roles', $notice);
        return '';
    }

    #[Route('POST', '/admin/roles/delete/?')]
    public function delete(string $id): string {
        $this->requirePermission(Permissions::ROLE_DELETE);
        $this->requireAction();
        try {
            $this->roles->delete($this->found($this->roles->findById((int)$id)));
            $this->done('/admin/roles', 'Deleted.');
        } catch (DpressException $e) {
            $this->done('/admin/roles', $e->getMessage());
        }
        return '';
    }

    protected function editor($form, ?Role $role): string {
        return $this->admin('dpress_admin:role/edit', [
            'title'    => $role === null ? 'New role' : 'Edit role',
            'form'     => $form,
            'role'     => $role,
            'back_url' => $this->router->url('/admin/roles'),
        ]);
    }

    /**
     * Only permissions something actually declared
     *
     * A role granting `made.up.permission` would sit in the table forever meaning nothing, and it
     * would read as a real grant in the audit history.
     */
    protected function knownPermissions(array $values): array {
        $wanted = array_map('strval', (array)($values['permissions'] ?? []));
        return array_values(array_filter($wanted, fn($permission) => $this->permissions->has($permission)));
    }
}
