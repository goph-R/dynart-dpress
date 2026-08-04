<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\User;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\RoleService;
use Dynart\Dpress\Service\UserService;

/**
 * Users and their roles
 *
 * Granting a role is a separate permission from editing a user (`role.assign`), because "may fix
 * somebody's name" and "may make somebody an administrator" are not the same trust.
 */
class UserAdminController extends AbstractAdminController {

    const SORTABLE = ['name', 'email', 'status', 'created_at'];

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        FormFactory $forms,
        ListRequest $list,
        protected UserService $users,
        protected RoleService $roles,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth, $forms, $list);
    }

    protected function section(): string {
        return 'users';
    }

    #[Route('GET', '/admin/users')]
    public function index(): string {
        $this->requirePermission(Permissions::USER_VIEW);
        return $this->admin('dpress:admin/user/list', [
            'title'      => 'Users',
            'can_create' => $this->can(Permissions::USER_CREATE),
            'new_url'    => $this->router->url('/admin/users/new'),
            'statuses'   => User::STATUSES,
            'list_id'    => 'user-list',
            'list_config' => [
                'endpoint' => $this->router->url('/admin/users/list'),
                'orderBy'  => 'name',
                'columns'  => [
                    'name'   => ['label' => 'Name', 'view' => 'link', 'options' => ['hrefProperty' => 'edit_url']],
                    'email'  => ['label' => 'Email'],
                    'roles'  => ['label' => 'Roles', 'view' => 'list', 'sortable' => false],
                    'status' => ['label' => 'Status', 'view' => 'badge'],
                    'created_at' => ['label' => 'Joined', 'view' => 'date'],
                ],
                'rowActions' => $this->rowActions(),
            ],
        ]);
    }

    #[Route('GET', '/admin/users/list')]
    public function rowsJson(): array {
        $this->requirePermission(Permissions::USER_VIEW);
        $context = $this->list->context(self::SORTABLE, ['search', 'status']);
        $rows = [];
        foreach ($this->users->findAll($context) as $user) {
            $rows[] = [
                'id'         => (int)$user['id'],
                'name'       => $user['name'],
                'email'      => $user['email'],
                'status'     => $user['status'],
                'created_at' => $user['created_at'],
                'roles'      => $this->users->roleNames((int)$user['id']),
                'edit_url'   => $this->router->url('/admin/users/edit/'.$user['id']),
            ];
        }
        return $this->rows($rows, $this->users->countAll($context));
    }

    protected function rowActions(): array {
        $actions = [];
        if ($this->can(Permissions::USER_UPDATE)) {
            $actions[] = ['type' => 'edit', 'title' => 'Edit', 'icon' => 'Edit',
                          'link' => $this->router->url('/admin/users/edit/')];
        }
        if ($this->can(Permissions::USER_DELETE)) {
            $actions[] = ['type' => 'delete', 'title' => 'Delete', 'icon' => 'Delete',
                          'post' => $this->router->url('/admin/users/delete/'),
                          'confirm' => 'Delete this account? Their posts stay, with the author gone.'];
        }
        return $actions;
    }

    #[Route('GET', '/admin/users/new')]
    #[Route('POST', '/admin/users/new')]
    public function create(): string {
        $this->requirePermission(Permissions::USER_CREATE);
        $form = $this->forms->create(AdminForms::USER, ['roles' => $this->roleOptions()]);
        if ($form->process()) {
            try {
                $form->handle(function ($form) {
                    $values = $form->values();
                    return $this->users->create(
                        (string)$values['email'], (string)$values['password'], (string)$values['name'],
                        $this->allowedRoles($values), (string)($values['status'] ?? User::STATUS_ACTIVE)
                    );
                });
                $this->done('/admin/users', 'Created.');
            } catch (DpressException $e) {
                $form->addError($e->getMessage());
            }
        }
        return $this->editor($form, null);
    }

    #[Route('GET', '/admin/users/edit/?')]
    #[Route('POST', '/admin/users/edit/?')]
    public function edit(string $id): string {
        $this->requirePermission(Permissions::USER_UPDATE);
        $user = $this->found($this->users->findById((int)$id));
        $form = $this->forms->create(AdminForms::USER, [
            'user'  => $user,
            'roles' => $this->roleOptions(),
            'selected_roles' => $this->users->roleNames($user->id),
        ]);
        if ($form->process()) {
            try {
                $form->handle(fn($form) => $this->save($user, $form->values()));
                $this->done('/admin/users', 'Saved.');
            } catch (DpressException $e) {
                $form->addError($e->getMessage());
            }
        }
        return $this->editor($form, $user);
    }

    /**
     * Applies what the form collected, each through its own service method
     *
     * `changeEmail()`, `setPassword()` and `setStatus()` rather than assigning the columns: each
     * emits its own event, and an empty password field means "leave it alone" rather than "set it
     * to nothing".
     */
    protected function save(User $user, array $values): User {
        $user->name = trim((string)$values['name']);
        $this->users->update($user);
        if (($values['email'] ?? '') !== '' && $this->users->normalizeEmail($values['email']) !== $user->email) {
            $this->users->changeEmail($user, (string)$values['email']);
        }
        if (($values['password'] ?? '') !== '') {
            $this->users->setPassword($user, (string)$values['password']);
        }
        if (($values['status'] ?? '') !== '' && $values['status'] !== $user->status) {
            $this->users->setStatus($user, (string)$values['status']);
        }
        $this->applyRoles($user, $values);
        return $user;
    }

    /**
     * Grants and revokes the difference, rather than rewriting the set
     *
     * A revoke of a role the user never had would be a no-op with an audit row behind it, and the
     * history of "who is an administrator" is worth keeping readable.
     */
    protected function applyRoles(User $user, array $values): void {
        if (!array_key_exists('roles', $values) || !$this->can(Permissions::ROLE_ASSIGN)) {
            return;
        }
        $wanted = $this->allowedRoles($values);
        $current = $this->users->roleNames($user->id);
        foreach (array_diff($wanted, $current) as $name) {
            $this->users->grantRole($user, $name);
        }
        foreach (array_diff($current, $wanted) as $name) {
            $this->users->revokeRole($user, $name);
        }
    }

    /**
     * Only names that are real roles, and only if this person may assign roles at all
     *
     * The checkbox list came from the browser: a hand-written POST naming `admin` has to be
     * checked here, not trusted because the rendered form did not offer it.
     */
    protected function allowedRoles(array $values): array {
        if (!$this->can(Permissions::ROLE_ASSIGN)) {
            return [];
        }
        $known = array_column($this->roles->findAll(), 'name');
        return array_values(array_intersect(array_map('strval', (array)($values['roles'] ?? [])), $known));
    }

    #[Route('POST', '/admin/users/delete/?')]
    public function delete(string $id): string {
        $this->requirePermission(Permissions::USER_DELETE);
        $this->requireAction();
        $user = $this->found($this->users->findById((int)$id));
        if ($user->id === (int)$this->currentUser()->id()) {
            $this->done('/admin/users', 'You cannot delete your own account.');
            return '';
        }
        try {
            $this->users->delete($user);
            $this->done('/admin/users', 'Deleted.');
        } catch (DpressException $e) {
            $this->done('/admin/users', $e->getMessage());
        }
        return '';
    }

    protected function editor($form, ?User $user): string {
        return $this->admin('dpress:admin/user/edit', [
            'title'    => $user === null ? 'New user' : 'Edit user',
            'form'     => $form,
            'user'     => $user,
            'narrow'   => true,
            'back_url' => $this->router->url('/admin/users'),
        ]);
    }

    /**
     * @return array [name => label], keyed by name because that is what the service grants by
     */
    protected function roleOptions(): array {
        $options = [];
        foreach ($this->roles->findAll() as $role) {
            $options[$role['name']] = $role['label'] !== '' ? $role['label'] : $role['name'];
        }
        return $options;
    }
}
