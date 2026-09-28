<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\Micro;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Content\CodeAssets;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Service\SettingFields;
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

    /** The Site tab: everything a visitor would notice changing */
    const TAB_SITE = 'site';

    /** The Theme tab: which theme draws the site, and the colours of its code blocks */
    const TAB_THEME = 'theme';

    /** The Admin tab: how the admin itself behaves, which no visitor ever sees */
    const TAB_ADMIN = 'admin';

    /**
     * The settings this screen writes, and how each is read back
     *
     * A registry rather than a `const` since 0.62.0, because a plugin adding a settings field
     * through `form.admin_settings:created` could watch it render and then not be saved - the
     * loop below iterated a constant it had no way into. `DpressServices::registerSettingFields()`
     * seeds it with these twelve.
     */
    protected function fields(): array {
        return Micro::get(SettingFields::class)->types();
    }

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
        protected MediaService $media,
        protected MediaView $mediaView,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth, $forms, $list);
    }

    protected function section(): string {
        return 'settings';
    }

    /**
     * Every timezone PHP knows, as a select
     *
     * A select rather than a text field because the value is not free text - a typo silently falls
     * back to UTC and the site's dates are a few hours wrong in a way nobody would think to check.
     * The list is long, and a long list somebody scrolls beats a short one that is missing theirs.
     *
     * @return array [identifier => identifier]
     */
    protected function timezoneOptions(): array {
        $zones = \DateTimeZone::listIdentifiers();
        return array_combine($zones, $zones);
    }

    #[Route('GET', '/admin/settings')]
    #[Route('POST', '/admin/settings')]
    public function index(): string {
        $this->requirePermission(Permissions::SETTING_VIEW);
        $values = $this->currentValues();
        $form = $this->forms->create(AdminForms::SETTINGS, [
            'timezones' => $this->timezoneOptions(),
            // spelled out rather than left as `post`/`root`: what somebody is choosing is an
            // address, so the address is what the select should say
            'post_paths' => [
                Setting::POST_PATH_PREFIXED => '/post/the-slug',
                Setting::POST_PATH_ROOT     => '/the-slug',
            ],
            'values' => $values,
            // what a plugin added, looked up here like every other list this form needs
            'registered_fields' => Micro::get(SettingFields::class)->formFields(),
            // the thumbnail the field shows for what is already chosen, rendered here because a
            // template has no business asking a service what a media id looks like
            'site_logo_preview' => $this->mediaPreview((int)($values[Setting::SITE_LOGO] ?? 0)),
            'site_icon_preview' => $this->mediaPreview((int)($values[Setting::SITE_ICON] ?? 0)),
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
            'tabs'      => $this->settingsTabs(self::TAB_SITE),
            'form'      => $form,
            'can_save'  => $this->can(Permissions::SETTING_UPDATE),
        ]);
    }

    /**
     * The Theme tab: the active theme, the code block colours, and what is installed
     */
    #[Route('GET', '/admin/settings/theme')]
    #[Route('POST', '/admin/settings/theme')]
    public function themeTab(): string {
        $this->requirePermission(Permissions::SETTING_VIEW);
        $form = $this->forms->create(AdminForms::THEME_SETTINGS, [
            'themes' => $this->themeOptions(),
            // `none` rather than '': an empty setting is read as absent and answers with the
            // default, so "off" has to be a word
            'code_themes' => [CodeAssets::NONE => 'No highlighting'] + CodeAssets::THEMES,
            'values' => $this->currentValues(),
        ]);
        if ($form->process()) {
            $this->requirePermission(Permissions::SETTING_UPDATE);
            try {
                $form->handle(fn($form) => $this->save($form->values()));
                $this->done('/admin/settings/theme', 'Saved.');
            } catch (DpressException $e) {
                // activating validates the name, and a theme that went missing says so here
                $form->addError($e->getMessage());
            }
        }
        return $this->admin('dpress_admin:settings', [
            'title'     => 'Settings',
            'tabs'      => $this->settingsTabs(self::TAB_THEME),
            'form'      => $form,
            'can_save'  => $this->can(Permissions::SETTING_UPDATE),
            'can_theme' => $this->can(Permissions::THEME_SWITCH),
            'themes'    => $this->themes->all(),
            'active_theme' => $this->themes->active(),
        ]);
    }

    /**
     * The Admin tab
     *
     * Its own form and its own address, so saving it writes only what is on it: `save()` skips a
     * name the form did not carry, which is what keeps the Site tab's checkboxes from being read
     * as unticked by a form that never showed them - and the other way round.
     */
    #[Route('GET', '/admin/settings/admin')]
    #[Route('POST', '/admin/settings/admin')]
    public function adminTab(): string {
        $this->requirePermission(Permissions::SETTING_VIEW);
        $form = $this->forms->create(AdminForms::ADMIN_SETTINGS, ['values' => $this->currentValues()]);
        if ($form->process()) {
            $this->requirePermission(Permissions::SETTING_UPDATE);
            $form->handle(fn($form) => $this->save($form->values()));
            $this->done('/admin/settings/admin', 'Saved.');
        }
        return $this->admin('dpress_admin:settings', [
            'title'    => 'Settings',
            'tabs'     => $this->settingsTabs(self::TAB_ADMIN),
            'form'     => $form,
            'can_save' => $this->can(Permissions::SETTING_UPDATE),
        ]);
    }

    protected function settingsTabs(string $current): array {
        return $this->tabs([
            self::TAB_SITE  => ['label' => 'Site', 'route' => '/admin/settings', 'permission' => Permissions::SETTING_VIEW],
            self::TAB_THEME => ['label' => 'Theme', 'route' => '/admin/settings/theme', 'permission' => Permissions::SETTING_VIEW],
            self::TAB_ADMIN => ['label' => 'Admin UI', 'route' => '/admin/settings/admin', 'permission' => Permissions::SETTING_VIEW],
        ], $current);
    }

    /**
     * Writes what changed
     *
     * Each goes through `SettingService::set()`, which is what makes it audited - "who turned
     * registration on" is answerable afterwards.
     */
    protected function save(array $values): void {
        foreach ($this->fields() as $name => $type) {
            if (!array_key_exists($name, $values)) {
                continue;
            }
            $this->settings->set($name, match ($type) {
                'bool' => $values[$name] ? '1' : '0',
                'int'  => (string)(int)$values[$name],
                // nothing chosen is stored as nothing rather than as `0`, so "no logo" reads the
                // same in the database as it does on the screen
                'media' => (int)$values[$name] > 0 ? (string)(int)$values[$name] : '',
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
        foreach ($this->fields() as $name => $type) {
            $values[$name] = match ($type) {
                'bool' => $this->settings->getBool($name) ? '1' : '',
                'int'  => (string)$this->settings->getInt($name),
                // '' rather than '0', or the field would offer a Remove button for a file nobody
                // has chosen
                'media' => $this->settings->getInt($name) > 0 ? (string)$this->settings->getInt($name) : '',
                default => (string)$this->settings->get($name, ''),
            };
        }
        $values['theme'] = $this->themes->active();
        return $values;
    }

    /**
     * The thumbnail the field shows for what is already chosen
     *
     * Empty when nothing is, and empty when what was chosen has since been deleted - the same
     * question `AbstractController::brandingAsset()` asks when it renders the header.
     */
    protected function mediaPreview(int $id): string {
        if ($id <= 0) {
            return '';
        }
        $media = $this->media->findById($id);
        return $media === null || $media->isDeleted() ? '' : $this->mediaView->tag($media, 'thumb');
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
