<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Plugin\Plugin;
use Dynart\Dpress\Plugin\PluginService;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;

/**
 * What is installed, and what is switched on
 *
 * The screen a broken plugin has to be disableable from, which is why nothing in `PluginService`
 * is allowed to throw during boot: if it were, the plugin would have taken this page with it.
 */
class PluginAdminController extends AbstractAdminController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        FormFactory $forms,
        ListRequest $list,
        protected PluginService $plugins,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth, $forms, $list);
    }

    protected function section(): string {
        return 'plugins';
    }

    #[Route('GET', '/admin/plugins')]
    public function index(): string {
        $this->requirePermission(Permissions::PLUGIN_MANAGE);
        return $this->admin('dpress_admin:plugin/list', [
            'title'   => 'Plugins',
            'path'    => $this->plugins->path(),
            'off'     => $this->plugins->isOff(),
            'off_key' => PluginService::CONFIG_OFF,
            'list_id' => 'plugin-list',
            'list_config' => [
                'endpoint' => $this->router->url('/admin/plugins/list'),
                'columns'  => [
                    // nothing here sorts: `page()` answers with the folder listing, in name order
                    'id'      => ['label' => '#', 'align' => 'right', 'width' => '1%', 'sortable' => false],
                    'title'   => ['label' => 'Plugin', 'sortable' => false],
                    'version' => ['label' => 'Version', 'sortable' => false, 'width' => '1%'],
                    'status'  => ['label' => 'Status', 'view' => 'badge', 'sortable' => false, 'options' => [
                        'labels' => [
                            Plugin::STATUS_ENABLED   => 'Enabled',
                            Plugin::STATUS_AVAILABLE => 'Available',
                            Plugin::STATUS_FAILED    => 'Failed',
                            Plugin::STATUS_MISSING   => 'Missing',
                        ],
                        'classes' => [
                            Plugin::STATUS_ENABLED   => 'published',
                            Plugin::STATUS_AVAILABLE => 'draft',
                            Plugin::STATUS_FAILED    => 'blocked',
                            Plugin::STATUS_MISSING   => 'blocked',
                        ],
                    ]],
                    'note'    => ['label' => '', 'sortable' => false],
                ],
                'rowActions'   => [],
                'groupActions' => $this->groupActions(),
                'firstPage'    => $this->page(),
            ],
        ]);
    }

    #[Route('GET', '/admin/plugins/list')]
    public function rowsJson(): array {
        $this->requirePermission(Permissions::PLUGIN_MANAGE);
        return $this->page();
    }

    /**
     * The folder listing, plus anything enabled that is no longer in it
     *
     * The **name** is the id, because a plugin has no row anywhere - it is a folder. That is what
     * the group actions post back, and `Setting::PLUGINS` is a list of exactly these.
     */
    protected function page(): array {
        $this->plugins->load();
        $rows = [];
        foreach ($this->plugins->all() as $name => $plugin) {
            $rows[] = [
                'id'      => $name,
                'title'   => $plugin->title(),
                'version' => $plugin->version(),
                'status'  => $plugin->status,
                'note'    => $plugin->error !== '' ? $plugin->error : $plugin->description(),
            ];
        }
        return $this->rows($rows, count($rows));
    }

    protected function groupActions(): array {
        $base = $this->router->url('/admin/plugins');
        return [
            ['type' => 'publish', 'label' => 'Enable selected', 'post' => $base.'/enable-selected'],
            ['type' => 'unpublish', 'label' => 'Disable selected', 'post' => $base.'/disable-selected',
             'confirm' => 'Disable the selected plugins? Their tables and their data are left alone.'],
        ];
    }

    // --- the group actions ---

    #[Route('POST', '/admin/plugins/enable-selected')]
    public function enableMany(): string {
        return $this->apply(
            fn(string $name) => $this->plugins->enable($name),
            'enabled',
            ' Run `dpress upgrade` if any of them bring tables of their own.'
        );
    }

    #[Route('POST', '/admin/plugins/disable-selected')]
    public function disableMany(): string {
        return $this->apply(fn(string $name) => $this->plugins->disable($name), 'disabled');
    }

    /**
     * The group-action shape, on names rather than ids
     *
     * A plugin is identified by its folder name rather than by an id, which is why the ids are
     * read here rather than coerced to `int` somewhere shared. Each is tried on its own, one
     * refusal does not abandon the rest, and the page says what happened.
     *
     * This is the last group action in the admin: deleting many at once went in 0.31.0, and
     * enabling six plugins at a time is the thing selection is actually good for.
     */
    protected function apply(callable $each, string $verb, string $suffix = ''): string {
        $this->requirePermission(Permissions::PLUGIN_MANAGE);
        $this->requireAction();
        $names = array_filter(array_map('strval', (array)$this->request->get('ids', [])));
        if (!$names) {
            $this->done('/admin/plugins', 'Nothing was selected.');
            return '';
        }
        $done = 0;
        $refused = [];
        foreach ($names as $name) {
            try {
                $each($name);
                $done++;
            } catch (DpressException $e) {
                $refused[] = $e->getMessage();
            }
        }
        $notice = ($done === 1 ? '1 plugin ' : $done.' plugins ').$verb.'.';
        $notice .= $done > 0 ? $suffix : '';
        $this->done('/admin/plugins', trim($notice.' '.join(' ', array_unique($refused))));
        return '';
    }
}
