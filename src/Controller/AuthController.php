<?php

namespace Dynart\Dpress\Controller;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Form\CoreForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Mail\MailerInterface;
use Dynart\Dpress\Security\AuthCookies;
use Dynart\Dpress\Service\AuthService;
use Dynart\Dpress\Service\UserService;

/**
 * Logging in, signing up and recovering a password
 */
class AuthController extends AbstractController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        protected FormFactory $forms,
        protected AuthService $auth,
        protected UserService $users,
        protected AuthCookies $cookies,
        protected MailerInterface $mailer,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth);
    }

    #[Route('BOTH', '/login')]
    public function login(): string {
        if ($this->isLoggedIn()) {
            $this->app()->redirect('/');
        }
        $form = $this->forms->create(CoreForms::LOGIN);
        if ($form->process()) {
            try {
                $tokens = $form->handle(fn($f) => $this->auth->login($f->value('email'), $f->value('password')));
                $this->cookies->set($tokens);
                $this->app()->redirect('/');
            } catch (DpressException $e) {
                $form->addError($e->getMessage());
            }
        }
        return $this->render('dpress:auth/login', ['form' => $form, 'title' => 'Log in']);
    }

    /**
     * POST only, so a link planted on another page cannot log a visitor out
     */
    #[Route('POST', '/logout')]
    public function logout(): string {
        $refreshToken = $this->cookies->refreshToken();
        if ($refreshToken !== null) {
            $this->auth->logout($refreshToken);
        }
        $this->cookies->clear();
        $this->app()->redirect('/');
        return '';
    }

    #[Route('BOTH', '/register')]
    public function register(): string {
        if (!$this->registrationOpen()) {
            return $this->message('Registration is closed', 'This site is not accepting new registrations.');
        }
        if ($this->isLoggedIn()) {
            $this->app()->redirect('/');
        }
        $form = $this->forms->create(CoreForms::REGISTER);
        if ($form->process()) {
            try {
                $form->handle(fn($f) => $this->users->register(
                    $f->value('email'),
                    $f->value('password'),
                    $f->value('name')
                ));
                return $this->message(
                    'Almost done',
                    'Your account has been created. An administrator has to activate it before you can log in.'
                );
            } catch (DpressException $e) {
                $form->addError($e->getMessage());
            }
        }
        return $this->render('dpress:auth/register', ['form' => $form, 'title' => 'Register']);
    }

    /**
     * The answer is the same whether the address is known or not, so this never becomes a way of
     * finding out who has an account here.
     */
    #[Route('BOTH', '/forgot-password')]
    public function forgotPassword(): string {
        $form = $this->forms->create(CoreForms::FORGOT_PASSWORD);
        if ($form->process()) {
            $form->handle(function($f) {
                $email = $f->value('email');
                $token = $this->auth->createPasswordResetToken($email);
                if ($token !== null) {
                    $this->sendPasswordResetMail($email, $token);
                }
                return null;
            });
            return $this->message(
                'Check your inbox',
                'If that address belongs to an account, a password reset link is on its way.'
            );
        }
        return $this->render('dpress:auth/forgot-password', ['form' => $form, 'title' => 'Forgotten password']);
    }

    #[Route('BOTH', '/reset-password')]
    public function resetPassword(): string {
        $form = $this->forms->create(CoreForms::RESET_PASSWORD, [
            'token' => (string)$this->request->get('token', '')
        ]);
        if ($form->process()) {
            try {
                $form->handle(fn($f) => $this->auth->resetPassword($f->value('token'), $f->value('password')));
                return $this->message(
                    'Password changed',
                    'Your password has been changed. You can log in with it now.',
                    ['url' => $this->router->url('/login'), 'label' => 'Log in']
                );
            } catch (DpressException $e) {
                $form->addError($e->getMessage());
            }
        }
        return $this->render('dpress:auth/reset-password', ['form' => $form, 'title' => 'Choose a new password']);
    }

    protected function sendPasswordResetMail(string $email, string $token): void {
        $user = $this->users->findByEmail($this->users->normalizeEmail($email));
        $this->mailer->send(
            $user !== null ? $user->name : '',
            $email,
            'Reset your password',
            'dpress:mail/password-reset',
            [
                'name'       => $user !== null ? $user->name : '',
                'url'        => $this->router->url('/reset-password', ['token' => $token]),
                'expires_in' => $this->humanTtl($this->auth->resetTtl()),
                'site_name'  => $this->siteName(),
                'subject'    => 'Reset your password',
            ]
        );
    }

    protected function humanTtl(int $seconds): string {
        if ($seconds >= 3600 && $seconds % 3600 === 0) {
            $hours = intdiv($seconds, 3600);
            return $hours.' hour'.($hours > 1 ? 's' : '');
        }
        $minutes = max(1, intdiv($seconds, 60));
        return $minutes.' minute'.($minutes > 1 ? 's' : '');
    }
}
