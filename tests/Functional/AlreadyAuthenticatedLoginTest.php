<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * /login, reached by somebody who is already logged in.
 *
 * It happens by going back in the browser's history far enough to land on the form the session was
 * opened with, and the screen it shows is a dead end: the only thing it can offer is a login that
 * has already succeeded. Both halves are pinned here because they are implemented in two different
 * places and only one of them is obvious - the GET in App\Controller\SecurityController::login(),
 * and the POST of that same restored form in App\Security\LdapAuthenticator::supports(), which
 * declines it so that it falls through to the controller instead of being checked as credentials
 * nobody re-typed.
 *
 * The second test also pins what must *not* happen: a stray POST on that screen must never cost the
 * visitor the session they already have.
 */
class AlreadyAuthenticatedLoginTest extends FunctionalTestCase
{
    public function testOpeningTheLoginScreenSendsALoggedInVisitorHome(): void
    {
        $this->client->loginUser($this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'already.in'));

        $this->client->request('GET', '/login');

        self::assertResponseRedirects('/');
    }

    public function testResubmittingTheLoginFormSendsALoggedInVisitorHomeWithoutTouchingTheirSession(): void
    {
        $user = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'already.in.too');
        $this->client->loginUser($user);

        // Whatever the restored form happens to carry: neither the credentials nor the CSRF token
        // are read at all, which is the point - nothing here reaches LDAP.
        $this->client->request('POST', '/login', [
            '_username' => 'somebody.else',
            '_password' => 'whatever',
            '_csrf_token' => 'stale',
        ]);

        self::assertResponseRedirects('/');

        $token = static::getContainer()->get('security.token_storage')->getToken();
        self::assertNotNull($token);
        self::assertSame($user->getUserIdentifier(), $token->getUserIdentifier());
    }
}
