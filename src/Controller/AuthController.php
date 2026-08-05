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
use Dynart\Dpress\Security\RateLimiter;
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
        protected RateLimiter $limiter,
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
            $account = $this->account($form->value('email'));
            if ($this->limiter->reachedEither(RateLimiter::SCOPE_LOGIN, $account, $this->address())) {
                $form->addError($this->tryAgainLater(RateLimiter::SCOPE_LOGIN, $account));
            } else {
                try {
                    $tokens = $form->handle(fn($f) => $this->auth->login($f->value('email'), $f->value('password')));
                    // the account only - see `RateLimiter::clear()`
                    $this->limiter->clear(RateLimiter::SCOPE_LOGIN, $account);
                    $this->cookies->set($tokens);
                    $this->app()->redirect('/');
                } catch (DpressException $e) {
                    $this->limiter->record(RateLimiter::SCOPE_LOGIN, $account, $this->address());
                    $form->addError($e->getMessage());
                }
            }
        }
        return $this->render('dpress:auth/login', ['form' => $form, 'title' => 'Log in']);
    }

    /**
     * The key an attempt is counted against, from whatever was typed in
     *
     * Normalised the same way a real login is, so `A@B.com` and `a@b.com` are one account and
     * not two allowances. Counted **whether or not the address belongs to anybody**: an unknown
     * address that was not counted would make the limit itself a way of asking who has an
     * account here, and guessing addresses is how a spray attack starts.
     */
    protected function account(mixed $email): string {
        return $this->users->normalizeEmail((string)$email);
    }

    /**
     * Where the request came from, or '' when there is nothing to go on
     *
     * `Request::ip()` is `REMOTE_ADDR` unless a trusted proxy said otherwise - see
     * `request.trusted_proxies`, and set it if this site is behind one, because otherwise every
     * visitor arrives as the proxy and shares one allowance.
     */
    protected function address(): string {
        return (string)($this->request->ip() ?? '');
    }

    /**
     * The one thing a limited request is told
     *
     * No mention of which of the two limits it was, and no mention of the account: the message
     * is the same for the person who mistyped their own password five times and for somebody
     * working through a list.
     */
    protected function tryAgainLater(string $scope, string $account): string {
        $seconds = $this->limiter->retryAfterEither($scope, $account, $this->address());
        return 'Too many attempts. Try again in '.$this->limiter->humanRetryAfter($seconds).'.';
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
            $account = $this->account($form->value('email'));
            // Over the limit, the answer is the *same page* rather than an error. A reset form
            // that says "too many attempts" tells anybody willing to try that somebody has been
            // asking about this address, and the endpoint exists precisely so that it says
            // nothing about who does and does not have an account. Nothing is sent, and the
            // mailbox stops being something a stranger can fill.
            if (!$this->limiter->reachedEither(RateLimiter::SCOPE_PASSWORD_RESET, $account, $this->address())) {
                $this->limiter->record(RateLimiter::SCOPE_PASSWORD_RESET, $account, $this->address());
                $form->handle(function($f) {
                    $email = $f->value('email');
                    $token = $this->auth->createPasswordResetToken($email);
                    if ($token !== null) {
                        $this->sendPasswordResetMail($email, $token);
                    }
                    return null;
                });
            }
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
            // the token stands in for the account here, because until it resolves there is no
            // account to name - so a single link can be guessed at a few times and no more
            $token = (string)$form->value('token');
            if ($this->limiter->reachedEither(RateLimiter::SCOPE_PASSWORD_RESET_TOKEN, $token, $this->address())) {
                $form->addError($this->tryAgainLater(RateLimiter::SCOPE_PASSWORD_RESET_TOKEN, $token));
            } else {
                try {
                    $form->handle(fn($f) => $this->auth->resetPassword($f->value('token'), $f->value('password')));
                    return $this->message(
                        'Password changed',
                        'Your password has been changed. You can log in with it now.',
                        ['url' => $this->router->url('/login'), 'label' => 'Log in']
                    );
                } catch (DpressException $e) {
                    $this->limiter->record(RateLimiter::SCOPE_PASSWORD_RESET_TOKEN, $token, $this->address());
                    $form->addError($e->getMessage());
                }
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
