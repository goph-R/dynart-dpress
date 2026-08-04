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
use Dynart\Dpress\Entity\Setting;
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
        return $this->siteAsset((string)Micro::get(SettingService::class)->get(Setting::SITE_LOGO, ''));
    }

    /**
     * The icon in the browser's tab, as a URL, or '' when there is none
     */
    protected function siteIcon(): string {
        return $this->siteAsset((string)Micro::get(SettingService::class)->get(Setting::SITE_ICON, ''));
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
     */
    protected function render(string $template, array $variables = []): string {
        $this->view->set('current_user', $this->currentUser());
        $this->view->set('site_name', $this->siteName());
        $this->view->set('site_logo', $this->siteLogo());
        $this->view->set('site_icon', $this->siteIcon());
        $this->view->set('registration_open', $this->registrationOpen());
        $this->view->set('main_menu', $this->menu('main'));
        return $this->view->fetch($template, $variables);
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
