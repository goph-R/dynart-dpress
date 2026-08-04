<?php

namespace Dynart\Dpress\Controller;

use Dynart\Micro\Attribute\Authorize;
use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Form\CoreForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Security\AuthCookies;
use Dynart\Dpress\Service\AuthService;
use Dynart\Dpress\Service\UserService;

/**
 * The logged in user's own account
 *
 * `#[Authorize]` with no permission means "any logged in user", which is exactly right here.
 */
#[Authorize]
class ProfileController extends AbstractController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        protected FormFactory $forms,
        protected UserService $users,
        protected AuthService $auth,
        protected AuthCookies $cookies,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth);
    }

    #[Route('BOTH', '/profile')]
    public function profile(): string {
        $user = $this->auth->currentUser();
        if ($user === null) {
            $this->app()->redirect('/login');
        }
        $form = $this->forms->create(CoreForms::PROFILE, ['user' => $user]);
        $saved = false;
        if ($form->process()) {
            try {
                $form->handle(function($f) use ($user, &$saved) {
                    $user->name = $f->value('name');
                    $this->users->changeEmail($user, $f->value('email'));
                    $password = (string)$f->value('password');
                    if ($password !== '') {
                        $this->users->setPassword($user, $password);
                        // the password changed, so every other session of this user ends
                        $this->auth->revokeAllRefreshTokens($user->id);
                        $this->cookies->set($this->auth->issueTokens($user));
                    }
                    $saved = true;
                    return null;
                });
            } catch (DpressException $e) {
                $form->addError($e->getMessage());
            }
        }
        return $this->render('dpress:auth/profile', [
            'form'  => $form,
            'title' => 'Your profile',
            'saved' => $saved,
            'user'  => $user,
        ]);
    }
}
