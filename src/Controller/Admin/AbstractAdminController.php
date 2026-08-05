<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Authorize;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\Router;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Controller\AbstractController;
use Dynart\Dpress\Dpress;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\DpressForm;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;

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

    const LAYOUT = 'dpress_admin:layout';

    /**
     * The layout of a partial request: the `<main>` element on its own, no chrome around it
     *
     * The full layout fetches the same file, so what a partial answers with is by construction
     * what a whole page would have contained - there is no second definition to drift.
     */
    const LAYOUT_PARTIAL = 'dpress_admin:main';

    /** The query parameter that asks for the fragment instead of the page */
    const PARTIAL = 'ajax';

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
        $this->view->set('site_name', $this->siteName());
        // no `site_logo`: the admin wears dpress's logo, not the site's. The icon is the site's,
        // because that is the tab an editor keeps open alongside the site itself.
        $this->view->set('site_icon', $this->siteIcon());
        $this->view->set('admin_nav', $this->navigation());
        $this->view->set('admin_section', $this->section());
        $this->view->set('admin_layout', $this->isPartial() ? self::LAYOUT_PARTIAL : self::LAYOUT);
        $this->view->set('admin_route_param', $this->routeParam());
        $this->view->set('admin_url', $this->router->url('/admin'));
        // empty for somebody who may not add to the library, so the picker shows no upload pane.
        // The endpoint checks the permission too - this only keeps a useless control off screen.
        $this->view->set('media_upload_url', $this->can(Permissions::MEDIA_CREATE)
            ? $this->router->url('/admin/media/upload/json') : '');
        $this->view->set('notice', $this->notice());
        $this->view->set('action_form', $this->actionForm());
        // `title` and `narrow` are the two screen variables the chrome reads, and the layout
        // fetches `main.phtml` rather than passing its own variables down, so they have to be
        // view data as well. The composed title is here rather than in the layout because both
        // the `<title>` element and the fragment's `data-title` need exactly the same string.
        $this->view->set('narrow', !empty($variables['narrow']));
        $this->view->set('page_title', ((string)($variables['title'] ?? '') ?: 'Admin').' – '.($this->siteName() ?: 'dpress'));
        return $this->view->fetch($template, $variables);
    }

    /**
     * Is this a request for the fragment rather than the page?
     *
     * The URL is otherwise the same one, so every permission check on the way here is the same
     * one - a partial can never reach a screen the whole page could not.
     */
    protected function isPartial(): bool {
        return $this->request->get(self::PARTIAL) !== null;
    }

    /**
     * The query parameter routes travel in, or '' when the URLs are real paths
     *
     * The browser has to know which links stay inside the admin before it may answer one with a
     * partial load, and without rewriting every screen in the site shares one path - `index.php`
     * - with the route in a parameter. Getting this wrong only costs a partial load, because a
     * link that fails the test is followed the ordinary way.
     */
    protected function routeParam(): string {
        return $this->config->get(Router::CONFIG_USE_REWRITE, Router::DEFAULT_USE_REWRITE)
            ? '' : (string)$this->config->get(Router::CONFIG_ROUTE_PARAMETER, Router::DEFAULT_ROUTE_PARAMETER);
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
        $form = $this->forms->create(AdminForms::ACTION);
        if (!$form->process()) {
            $this->app()->sendError(403);
        }
        $this->processedAction = $form;
    }

    /** @var DpressForm|null The action form this request validated, holding the token it minted */
    private ?DpressForm $processedAction = null;

    /**
     * The token the *next* action has to send
     *
     * `Form::process()` generates a fresh token every time it runs and stores it in the session,
     * so validating one action invalidates the one printed on the page. That is invisible while
     * every action reloads the page - the new page carries the new token - and fatal the moment
     * two of them happen without one, which is exactly what the editor's panel does: upload,
     * then attach. The second was refused as a forgery.
     *
     * So an action that answers with data hands the new token back, and the browser puts it in
     * the hidden form. Rotation stays, which is worth keeping: a token that leaked out of one
     * response is spent.
     */
    protected function actionToken(): string {
        $form = $this->processedAction ?? $this->actionForm();
        return (string)$form->value($form->csrfName());
    }

    /**
     * The answer to an action that returns data rather than a redirect
     *
     * Everything an action reports, plus the token for the next one. Going through one method
     * means a new action cannot forget it and leave the second click of a pair failing.
     */
    protected function answer(array $data = []): array {
        return $data + ['csrf' => $this->actionToken()];
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
     * The context for the first page of a list, as the browser is about to ask for it
     *
     * A list screen used to be two requests: the page, and then the rows the moment the list had
     * built itself. The page knows the answer to the second one already, so it renders it into
     * the configuration and the table arrives filled.
     *
     * Nothing is in the request yet on a first load, so **the sort comes out of the same config
     * that is about to be handed to the browser** - which is what its own hidden inputs get
     * primed with. That is the one thing here that has to stay honest: a seeded page ordered
     * differently from what the list thinks it is showing would rearrange itself on the first
     * click. Anything that *is* in the request - a filter, a sort, a page somebody linked to -
     * wins over the defaults, exactly as it does at the endpoint.
     */
    protected function firstPageContext(array $config, array $sortable, array $filters = []): array {
        $context = $this->list->context($sortable, $filters);
        if (!isset($context['order_by']) && !empty($config['orderBy'])) {
            $context['order_by'] = (string)$config['orderBy'];
            $context['order_dir'] = ($config['orderDir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        }
        if (!empty($config['pageSize']) && $this->request->get(ListRequest::MAX) === null) {
            $context['max'] = (int)$config['pageSize'];
        }
        return $context;
    }

    /** @var string[] Rendered once each: the same icon repeats across a screen */
    private array $icons = [];

    /**
     * The inline SVG of an icon, by name
     *
     * `icons/<name>.svg` in the package - a plain file, not a template. It holds no PHP, so
     * calling it one bought exactly one thing: a theme could replace it. That is the opposite of
     * what is wanted here, and the admin's views now say so themselves.
     *
     * Inline rather than an `<img>` so the icon takes the colour of the link or button it sits
     * in - muted in a row, inverted in the current navigation item, red on a delete hover.
     *
     * An icon this admin does not have falls back to a generic mark rather than a gap, so a
     * section or a row action a plugin adds is never invisible for want of a drawing.
     *
     * **The result is markup**, and that is what `icon` means everywhere it appears: in the
     * navigation, and in a row action, where the list assigns it as `innerHTML`. It is ours, not
     * an uploaded file, so there is nothing here to sanitise - but nothing may build it out of a
     * request either.
     */
    protected function icon(string $name): string {
        if (!isset($this->icons[$name])) {
            $path = Dpress::iconsPath().'/'.$name.'.svg';
            if (!is_file($path)) {
                $path = Dpress::iconsPath().'/section.svg';
            }
            $this->icons[$name] = trim((string)@file_get_contents($path));
        }
        return $this->icons[$name];
    }

    /**
     * The admin sections this user may open
     *
     * `icon` is its own key rather than the section's, so a section a plugin adds can point at an
     * icon that already exists instead of shipping one.
     *
     * @return array [['url' => ..., 'label' => ..., 'key' => ..., 'icon' => <svg markup>]]
     */
    protected function navigation(): array {
        $sections = [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'route' => '/admin', 'permission' => ''],
            ['key' => 'content',   'label' => 'Posts',     'icon' => 'content',   'route' => '/admin/content/post', 'permission' => Permissions::POST_VIEW],
            ['key' => 'pages',     'label' => 'Pages',     'icon' => 'pages',     'route' => '/admin/content/page', 'permission' => Permissions::PAGE_VIEW],
            ['key' => 'media',     'label' => 'Media',     'icon' => 'media',     'route' => '/admin/media', 'permission' => Permissions::MEDIA_VIEW],
            ['key' => 'taxonomy',  'label' => 'Taxonomy',  'icon' => 'taxonomy',  'route' => '/admin/categories', 'permission' => Permissions::CATEGORY_VIEW],
            ['key' => 'menus',     'label' => 'Menus',     'icon' => 'menus',     'route' => '/admin/menus', 'permission' => Permissions::MENU_VIEW],
            ['key' => 'users',     'label' => 'Users',     'icon' => 'users',     'route' => '/admin/users', 'permission' => Permissions::USER_VIEW],
            ['key' => 'roles',     'label' => 'Roles',     'icon' => 'roles',     'route' => '/admin/roles', 'permission' => Permissions::ROLE_VIEW],
            ['key' => 'settings',  'label' => 'Settings',  'icon' => 'settings',  'route' => '/admin/settings', 'permission' => Permissions::SETTING_VIEW],
        ];
        $result = [];
        foreach ($sections as $section) {
            if ($section['permission'] !== '' && !$this->can($section['permission'])) {
                continue;
            }
            $result[] = [
                'key'   => $section['key'],
                'label' => $section['label'],
                'icon'  => $this->icon($section['icon']),
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
