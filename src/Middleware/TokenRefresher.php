<?php

namespace Dynart\Dpress\Middleware;

use Dynart\Micro\MiddlewareInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Security\AuthCookies;
use Dynart\Dpress\Service\AuthService;

/**
 * Renews an expired access token before the validator sees the request
 *
 * Access tokens are short lived on purpose, which without this would mean a 401 error page every
 * fifteen minutes for somebody who never logged out.
 *
 * It never has to decode anything. The access cookie is set to expire slightly before its token
 * does, so the browser stops sending it while it would still be valid - a request from a logged
 * in user whose token has aged out simply arrives with **no** Authorization header and a refresh
 * cookie, which is exactly the case handled here.
 *
 * Runs between `JwtCookieReader` and `JwtValidator`:
 *
 * <pre>
 * $app->addMiddleware(JwtCookieReader::class, 40);
 * $app->addMiddleware(TokenRefresher::class, 45);
 * $app->addMiddleware(JwtValidator::class, 50);
 * </pre>
 */
class TokenRefresher implements MiddlewareInterface {

    public function __construct(
        private RequestInterface $request,
        private AuthCookies $cookies,
        private AuthService $auth,
    ) {}

    public function run(): void {
        if ($this->request->header('Authorization')) {
            return; // there is a usable access token already
        }
        $refreshToken = $this->cookies->refreshToken();
        if ($refreshToken === null) {
            return; // not logged in, which is not an error
        }
        try {
            $tokens = $this->auth->refresh($refreshToken);
        } catch (DpressException $e) {
            // the refresh token is spent, revoked or expired: drop the cookies and carry on as
            // an anonymous request, so a stale cookie can never lock somebody out of the site
            $this->cookies->clear();
            return;
        }
        $this->cookies->set($tokens);
        $this->request->setHeader('Authorization', 'Bearer '.$tokens['access']);
    }
}
