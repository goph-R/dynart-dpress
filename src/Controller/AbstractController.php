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

    protected function siteName(): string {
        return (string)$this->config->get(self::CONFIG_SITE_NAME, 'dpress');
    }

    protected function registrationOpen(): bool {
        return (bool)$this->config->get(self::CONFIG_REGISTRATION_OPEN, false);
    }

    /**
     * Renders a template with the variables every page needs
     */
    protected function render(string $template, array $variables = []): string {
        $this->view->set('current_user', $this->currentUser());
        $this->view->set('site_name', $this->siteName());
        $this->view->set('registration_open', $this->registrationOpen());
        return $this->view->fetch($template, $variables);
    }

    protected function message(string $title, string $message, array $link = []): string {
        return $this->render('dpress:auth/message', [
            'title'   => $title,
            'message' => $message,
            'link'    => $link,
        ]);
    }
}
