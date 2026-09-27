<?php

namespace Dynart\Dpress\Middleware;

use Dynart\Micro\MiddlewareInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Security\AuthCookies;
use Dynart\Dpress\Service\AuthService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Renews an expired access token before the validator sees the request - and drops one that
 * could never be valid
 *
 * Access tokens are short lived on purpose, which without this would mean a 401 error page every
 * fifteen minutes for somebody who never logged out. The access cookie is set to expire slightly
 * before its token does, so a logged in user whose token has aged out arrives with **no**
 * Authorization header and a refresh cookie, and gets new tokens here.
 *
 * **A token that is sent but does not verify is not an error either.** `JwtValidator` answers 401
 * for the whole request when it cannot verify the token it is given, and the cookie reader hands
 * it whatever the access cookie holds. So one cookie this site did not sign - the signing secret
 * changed (a reinstall, `dpress init` on a server that already had users), another dpress site
 * on the same host with the same cookie name, a clock far enough out - made **every** page a
 * 401, the login form and the login itself included: a visitor locked out of the site until
 * they found their browser's cookie settings. Such a token is taken off the request here, the
 * refresh cookie gets its chance, and when that fails too both cookies are cleared and the
 * request goes on anonymous. A page that needs a login still says 401; the others simply work.
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
        $header = (string)$this->request->header('Authorization', '');
        if ($header !== '') {
            if ($this->verifies($header)) {
                return; // there is a usable access token already
            }
            // unusable: the validator would answer 401 for the whole site. Off the request, so
            // it is decided below like a request that brought no token at all
            $this->request->setHeader('Authorization', '');
        }
        $refreshToken = $this->cookies->refreshToken();
        if ($refreshToken === null) {
            if ($header !== '') {
                $this->cookies->clear(); // or the browser sends the bad one with every request
            }
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

    /**
     * Would `JwtValidator` accept this header? The same decode it does, with the same secret
     *
     * Something that is not a Bearer token at all is left for whatever else reads the header.
     */
    protected function verifies(string $header): bool {
        if (!str_starts_with($header, 'Bearer ')) {
            return true;
        }
        try {
            JWT::decode(substr($header, 7), new Key($this->auth->secret(), $this->auth->algorithm()));
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
