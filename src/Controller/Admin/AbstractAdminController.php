<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Authorize;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\Micro;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Controller\AbstractController;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\DpressForm;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\SettingService;

/**
 * What every admin screen needs
 *
 * `#[Authorize]` on the class means any logged in user reaches the admin at all; each action
 * still checks the permission it actually needs, because "may open the admin" and "may delete a
 * user" are not the same question.
 *
 * **Every screen is two actions**: one that renders the page - the filter form, the buttons, the
 * editor - and, where there is a list, one that answers with JSON. The page is what the server
 * decides; the rows are what the browser asks for again on every sort, filter and page change.
 */
#[Authorize]
abstract class AbstractAdminController extends AbstractController {

    const LAYOUT = 'dpress:admin/layout';

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        protected FormFactory $forms,
        protected ListRequest $list,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth);
    }

    /**
     * Stops the request unless the user holds the permission
     *
     * A 403 rather than a redirect: the person is logged in and the answer is no, which is a
     * different thing from not knowing who they are.
     */
    protected function requirePermission(string $permission): void {
        if (!$this->can($permission)) {
            $this->app()->sendError(403);
        }
    }

    protected function can(string $permission): bool {
        $user = $this->currentUser();
        return $user !== null && $user->hasPermission($permission);
    }

    /**
     * Renders an admin screen
     *
     * The navigation is built here rather than in the layout, so a template does not have to ask
     * the user what it is allowed to show.
     */
    protected function admin(string $template, array $variables = []): string {
        $this->view->set('current_user', $this->currentUser());
        $this->view->set('site_name', (string)Micro::get(SettingService::class)->get(Setting::SITE_NAME, 'dpress'));
        $this->view->set('admin_nav', $this->navigation());
        $this->view->set('admin_section', $this->section());
        $this->view->set('notice', $this->notice());
        $this->view->set('action_form', $this->actionForm());
        return $this->view->fetch($template, $variables);
    }

    /**
     * The section of the navigation this controller is, so the layout can mark it
     */
    protected function section(): string {
        return '';
    }

    /** @var DpressForm|null Built once per request, so the rendered token is the stored one */
    private ?DpressForm $action = null;

    /**
     * The hidden CSRF form the layout renders for the row actions
     */
    protected function actionForm(): DpressForm {
        if ($this->action === null) {
            $this->action = $this->forms->create(AdminForms::ACTION);
            $this->action->generateCsrf();
        }
        return $this->action;
    }

    /**
     * True when this is a row action POST carrying a valid token
     *
     * A failed check is a 403 rather than a redirect with a message: a request without the token
     * did not come from a page of this admin, and there is nothing useful to tell whoever sent it.
     */
    protected function requireAction(): void {
        if (!$this->forms->create(AdminForms::ACTION)->process()) {
            $this->app()->sendError(403);
        }
    }

    /**
     * The ids a group action was given
     *
     * @return int[]
     */
    protected function actionIds(): array {
        $ids = $this->request->get('ids', []);
        return array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [$ids])));
    }

    /**
     * The shape every list endpoint answers with
     *
     * `total` is the count without the page, which is what the pager needs; `items` is one page.
     * Returning an array is what makes the framework send JSON.
     */
    protected function rows(array $items, int $total): array {
        return ['items' => array_values($items), 'total' => $total];
    }

    /**
     * The admin sections this user may open
     *
     * @return array [['url' => ..., 'label' => ..., 'key' => ...]]
     */
    protected function navigation(): array {
        $sections = [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'route' => '/admin', 'permission' => ''],
            ['key' => 'content',   'label' => 'Posts',     'route' => '/admin/content/post', 'permission' => Permissions::POST_VIEW],
            ['key' => 'pages',      'label' => 'Pages',     'route' => '/admin/content/page', 'permission' => Permissions::PAGE_VIEW],
            ['key' => 'media',     'label' => 'Media',     'route' => '/admin/media', 'permission' => Permissions::MEDIA_VIEW],
            ['key' => 'taxonomy',  'label' => 'Taxonomy',  'route' => '/admin/categories', 'permission' => Permissions::CATEGORY_VIEW],
            ['key' => 'menus',     'label' => 'Menus',     'route' => '/admin/menus', 'permission' => Permissions::MENU_VIEW],
            ['key' => 'users',     'label' => 'Users',     'route' => '/admin/users', 'permission' => Permissions::USER_VIEW],
            ['key' => 'roles',     'label' => 'Roles',     'route' => '/admin/roles', 'permission' => Permissions::ROLE_VIEW],
            ['key' => 'settings',  'label' => 'Settings',  'route' => '/admin/settings', 'permission' => Permissions::SETTING_VIEW],
        ];
        $result = [];
        foreach ($sections as $section) {
            if ($section['permission'] !== '' && !$this->can($section['permission'])) {
                continue;
            }
            $result[] = [
                'key'   => $section['key'],
                'label' => $section['label'],
                'url'   => $this->router->url($section['route']),
            ];
        }
        return $result;
    }

    /**
     * Redirects back to a list after a change, carrying a one-line result
     */
    protected function done(string $route, string $notice = '', array $params = []): void {
        if ($notice !== '') {
            $params['notice'] = $notice;
        }
        $this->app()->redirect($route, $params);
    }

    protected function notice(): string {
        return (string)$this->request->get('notice', '');
    }

    /**
     * The id in the path, or a 404 when the row is gone
     *
     * A missing row is a 404 rather than a redirect with a message: the URL names something that
     * does not exist, and that is what the status code is for.
     */
    protected function found(mixed $entity): mixed {
        if ($entity === null) {
            $this->app()->sendError(404);
        }
        return $entity;
    }
}
