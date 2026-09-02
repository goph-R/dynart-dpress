<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\SettingService;
use Dynart\Dpress\Theme\ThemeService;

/**
 * The settings an editor may change while the site runs
 *
 * Only these. Anything needed *before* the database is reachable - the connection, the JWT secret
 * - stays in `dpress.ini` and is deliberately not editable here.
 *
 * Switching the theme is its own permission, because it changes how every page of the site looks.
 */
class SettingsAdminController extends AbstractAdminController {

    /** The settings this screen writes, and how each is read back */
    const FIELDS = [
        Setting::SITE_NAME => 'string',
        Setting::SITE_DESCRIPTION => 'string',
        Setting::SITE_LOGO => 'string',
        Setting::SITE_ICON => 'string',
        Setting::REGISTRATION_OPEN => 'bool',
        Setting::POSTS_PER_PAGE => 'int',
    ];

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        FormFactory $forms,
        ListRequest $list,
        protected SettingService $settings,
        protected ThemeService $themes,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth, $forms, $list);
    }

    protected function section(): string {
        return 'settings';
    }

    #[Route('GET', '/admin/settings')]
    #[Route('POST', '/admin/settings')]
    public function index(): string {
        $this->requirePermission(Permissions::SETTING_VIEW);
        $form = $this->forms->create(AdminForms::SETTINGS, [
            'themes' => $this->themeOptions(),
            'values' => $this->currentValues(),
        ]);
        if ($form->process()) {
            $this->requirePermission(Permissions::SETTING_UPDATE);
            try {
                $form->handle(fn($form) => $this->save($form->values()));
                $this->done('/admin/settings', 'Saved.');
            } catch (DpressException $e) {
                $form->addError($e->getMessage());
            }
        }
        return $this->admin('dpress_admin:settings', [
            'title'     => 'Settings',
            'form'      => $form,
            'can_save'  => $this->can(Permissions::SETTING_UPDATE),
            'can_theme' => $this->can(Permissions::THEME_SWITCH),
            'themes'    => $this->themes->all(),
            'active_theme' => $this->themes->active(),
        ]);
    }

    /**
     * Writes what changed
     *
     * Each goes through `SettingService::set()`, which is what makes it audited - "who turned
     * registration on" is answerable afterwards.
     */
    protected function save(array $values): void {
        foreach (self::FIELDS as $name => $type) {
            if (!array_key_exists($name, $values)) {
                continue;
            }
            $this->settings->set($name, match ($type) {
                'bool' => $values[$name] ? '1' : '0',
                'int'  => (string)(int)$values[$name],
                default => trim((string)$values[$name]),
            });
        }
        // the theme is separate: it is its own permission, and activating validates the name
        if ($this->can(Permissions::THEME_SWITCH) && array_key_exists('theme', $values)) {
            $theme = (string)$values['theme'];
            if ($theme !== '' && $theme !== $this->themes->active()) {
                $this->themes->activate($theme);
            }
        }
    }

    protected function currentValues(): array {
        $values = [];
        foreach (self::FIELDS as $name => $type) {
            $values[$name] = match ($type) {
                'bool' => $this->settings->getBool($name) ? '1' : '',
                'int'  => (string)$this->settings->getInt($name),
                default => (string)$this->settings->get($name, ''),
            };
        }
        $values['theme'] = $this->themes->active();
        return $values;
    }

    /**
     * @return array [name => label] of every theme in `themes/`, plus the built-in templates
     */
    protected function themeOptions(): array {
        $options = [ThemeService::FALLBACK => 'The built-in templates'];
        foreach ($this->themes->all() as $name => $theme) {
            $options[$name] = $theme['title'].($theme['version'] !== '' ? ' '.$theme['version'] : '');
        }
        return $options;
    }
}
