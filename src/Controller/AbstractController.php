<?php

namespace Dynart\Dpress\Controller;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\Micro;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Micro\WebApp;
use Dynart\Dpress\Security\DpressUser;
use Dynart\Dpress\Content\Shortcodes;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Service\MenuService;
use Dynart\Dpress\Service\SettingService;

/**
 * What every CMS controller needs
 *
 * Controllers stay thin: they read the request, call a service, and render. Anything that
 * changes state belongs in a service, or it is invisible to plugins.
 */
abstract class AbstractController {

    const CONFIG_SITE_NAME = 'dpress.site_name';
    const CONFIG_REGISTRATION_OPEN = 'dpress.registration_open';

    public function __construct(
        protected ViewInterface $view,
        protected RouterInterface $router,
        protected RequestInterface $request,
        protected ConfigInterface $config,
        protected JwtAuthInterface $jwtAuth,
    ) {}

    /**
     * The application, for redirecting and finishing
     *
     * Taken from the container rather than injected: `WebApp` is the running application, not a
     * service, and asking for it in a constructor would make every controller un-instantiable
     * outside a request.
     */
    protected function app(): WebApp {
        return Micro::app();
    }

    protected function currentUser(): ?DpressUser {
        $user = $this->jwtAuth->user();
        return $user instanceof DpressUser ? $user : null;
    }

    protected function isLoggedIn(): bool {
        return $this->jwtAuth->user() !== null;
    }

    /**
     * Settings win over the config, so an editor can change these while the site runs
     */
    protected function siteName(): string {
        return (string)Micro::get(SettingService::class)->get(Setting::SITE_NAME, 'dpress');
    }

    protected function registrationOpen(): bool {
        return Micro::get(SettingService::class)->getBool(Setting::REGISTRATION_OPEN, false);
    }

    /**
     * The logo shown instead of the site's name, as a URL, or '' when there is none
     */
    protected function siteLogo(): string {
        return $this->brandingAsset(Setting::SITE_LOGO, Setting::CONFIG_DEFAULT_LOGO);
    }

    /**
     * The icon in the browser's tab, as a URL, or '' when there is none
     */
    protected function siteIcon(): string {
        return $this->brandingAsset(Setting::SITE_ICON, Setting::CONFIG_DEFAULT_ICON);
    }

    /**
     * A chosen library item, or the configured default when there is not one
     *
     * **The fallback is what makes choosing from the library safe.** A logo is chrome: it renders
     * on pages with no content on them, before anything has been uploaded, and somebody deleting
     * a file has to not be able to take the header down. Missing, deleted, purged and never-set
     * all arrive here the same way and leave by the same door.
     *
     * Soft-deleted counts as gone. An item in the library's bin is one somebody has said they do
     * not want, and going on showing it in the header until it is purged would be the surprise.
     */
    protected function brandingAsset(string $setting, string $configKey): string {
        $default = $this->siteAsset((string)$this->config->get($configKey, ''));
        $id = (int)Micro::get(SettingService::class)->get($setting, 0);
        if ($id <= 0) {
            return $default;
        }
        $media = Micro::get(MediaService::class)->findById($id);
        if ($media === null || $media->isDeleted()) {
            return $default;
        }
        return Micro::get(MediaView::class)->url($media);
    }

    /**
     * A setting that names a file, as a URL
     *
     * `/static/logo.svg` is resolved against `app.base_url`, so the value survives the site moving
     * out of a subfolder onto a domain of its own - which is exactly the move that would otherwise
     * silently break every stored absolute URL.
     *
     * Anything that already carries a scheme is left alone: another host, `//`, a `data:` URI. It
     * is a setting, so only somebody who may change settings can put anything there at all.
     */
    protected function siteAsset(string $value): string {
        $value = trim($value);
        if ($value === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $value) === 1) {
            return $value;
        }
        return rtrim((string)$this->config->get('app.base_url', ''), '/').'/'.ltrim($value, '/');
    }

    /**
     * Renders a template with the variables every page needs
     *
     * **Shortcodes are expanded over the finished page**, not over `body_html` on the way to a
     * template. Content HTML reaches a template from five places - a post, a page, and three
     * listings - and a theme may render any of them from a template of its own. Expanding at each
     * source is five chances to forget and one more for every theme; expanding here is one call
     * that nothing can miss.
     *
     * A marker is an HTML comment and can only have come from the markdown renderer, because raw
     * HTML never survives a document. So there is nothing else in a page this can match.
     *
     * A page with no shortcode in it pays for one `str_contains` - see `Shortcodes`.
     */
    protected function render(string $template, array $variables = []): string {
        $this->view->set('current_user', $this->currentUser());
        $this->view->set('site_name', $this->siteName());
        $this->view->set('site_logo', $this->siteLogo());
        $this->view->set('site_icon', $this->siteIcon());
        $this->view->set('registration_open', $this->registrationOpen());
        $this->view->set('main_menu', $this->menu('main'));
        return Micro::get(Shortcodes::class)->expand($this->view->fetch($template, $variables));
    }

    /**
     * Renders a menu place, or nothing when no menu is assigned to it
     *
     * Rendered here rather than in the layout so a template stays free of service lookups.
     */
    protected function menu(string $place): string {
        $items = Micro::get(MenuService::class)->tree($place);
        return empty($items) ? '' : $this->view->fetch('dpress:menu', ['items' => $items, 'place' => $place]);
    }

    protected function message(string $title, string $message, array $link = []): string {
        return $this->render('dpress:auth/message', [
            'title'   => $title,
            'message' => $message,
            'link'    => $link,
        ]);
    }
}
