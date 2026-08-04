<?php

namespace Dynart\Dpress\Security;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\ResponseInterface;
use Dynart\Micro\Middleware\JwtCookieReader;
use Dynart\Dpress\Service\AuthService;

/**
 * Puts the tokens in cookies and takes them back out
 *
 * The access token goes in the cookie `JwtCookieReader` reads, so the framework picks it up
 * without knowing anything about the CMS. The refresh token gets its own.
 *
 * **The access cookie is set to expire slightly before its token does.** The browser then stops
 * sending it while the token would still be valid, so a request arrives with no access token at
 * all rather than an expired one - and `TokenRefresher` can renew it quietly instead of the
 * validator throwing a 401 at somebody who is still logged in.
 */
class AuthCookies {

    const CONFIG_REFRESH_COOKIE_NAME = 'jwt.refresh_cookie_name';
    const CONFIG_COOKIE_SECURE = 'jwt.cookie_secure';
    const CONFIG_COOKIE_PATH = 'jwt.cookie_path';

    const DEFAULT_REFRESH_COOKIE_NAME = 'refresh_token';

    /** Taken off the access cookie lifetime, to cover a little clock skew */
    const EXPIRY_SKEW = 30;

    public function __construct(
        protected ConfigInterface $config,
        protected RequestInterface $request,
        protected ResponseInterface $response,
        protected AuthService $auth,
    ) {}

    public function accessCookieName(): string {
        return (string)$this->config->get(JwtCookieReader::CONFIG_COOKIE_NAME, JwtCookieReader::DEFAULT_COOKIE_NAME);
    }

    public function refreshCookieName(): string {
        return (string)$this->config->get(self::CONFIG_REFRESH_COOKIE_NAME, self::DEFAULT_REFRESH_COOKIE_NAME);
    }

    /**
     * @param array $tokens The `['access' => ..., 'refresh' => ...]` from `AuthService`
     */
    public function set(array $tokens): void {
        $accessLifetime = max(1, $this->auth->accessTtl() - self::EXPIRY_SKEW);
        $this->response->setCookie($this->accessCookieName(), $tokens['access'], $this->options($accessLifetime));
        $this->response->setCookie($this->refreshCookieName(), $tokens['refresh'], $this->options($this->auth->refreshTtl()));
    }

    public function clear(): void {
        $options = ['path' => $this->path(), 'secure' => $this->secure()];
        $this->response->clearCookie($this->accessCookieName(), $options);
        $this->response->clearCookie($this->refreshCookieName(), $options);
    }

    public function refreshToken(): ?string {
        $value = $this->request->cookie($this->refreshCookieName());
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function accessToken(): ?string {
        $value = $this->request->cookie($this->accessCookieName());
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function options(int $lifetime): array {
        return [
            'expires'  => time() + $lifetime,
            'path'     => $this->path(),
            'secure'   => $this->secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    protected function path(): string {
        return (string)$this->config->get(self::CONFIG_COOKIE_PATH, '/');
    }

    /**
     * Off by default so a plain HTTP development site works; turn it on in production
     */
    protected function secure(): bool {
        return (bool)$this->config->get(self::CONFIG_COOKIE_SECURE, false);
    }
}
