<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates a `.ics` fetch by the secret in its URL (App\Entity\User::$calendarToken), on the
 * `calendar` firewall alone.
 *
 * A subscribed agenda is fetched by Google's or Apple's servers, not by the reader's browser: there
 * is no cookie, no session and no login form to redirect to. The token is therefore the whole of
 * the identity - the same shape as an e-CO runner's join token, one layer lower.
 *
 * **Authenticating rather than checking by hand is the point.** Once the request carries the token's
 * owner as a real authenticated user, every rule the application already has answers unchanged:
 * App\Security\FeatureAccess, App\Security\StructureAccessChecker, App\Security\ProgramTimetableAccess
 * and the voters all read Security::getUser(), and a feed built on a second, parallel reading of
 * those rules is a feed that drifts away from the screen it copies.
 *
 * Stateless: no session is opened, so a calendar client polling every few hours leaves nothing
 * behind. `user_checker` on the firewall is what refuses a deactivated account here, exactly as it
 * does on a login.
 */
class CalendarTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->has('token');
    }

    public function authenticate(Request $request): Passport
    {
        $token = $request->attributes->getString('token');
        // An empty token must never match the accounts that have none: the column is nullable and
        // stays null until somebody is shown a link, so `WHERE calendar_token = ''` would be the one
        // query able to open an account nobody ever subscribed to.
        $user = '' === $token ? null : $this->users->findOneBy(['calendarToken' => $token]);

        if (null === $user) {
            throw new CustomUserMessageAuthenticationException('calendarTokenInvalidMessage');
        }

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /**
     * A plain 404, and not the 401 a firewall would answer by default.
     *
     * There is nothing here to retry with: the URL is the credential, so a challenge asks for
     * something the caller cannot produce, and some agenda clients respond to a 401 by prompting
     * their owner for a password that does not exist. « This calendar is not there » is also the
     * honest answer for a token that has been rotated away.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new Response('', Response::HTTP_NOT_FOUND);
    }
}
