<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The single place that turns App\Entity\User::$inactiveDate into a refusal to log in.
 *
 * Wired as the `user_checker` of every firewall that authenticates somebody (config/packages/
 * security.yaml), rather than copied into each authenticator: there are three of them
 * (LdapAuthenticator, ApiLdapAuthenticator, MagicLinkAuthenticator) plus the JWT one the api
 * firewall builds for itself, and a check written four times is a check that will one day only be
 * written three.
 *
 * Symfony calls this on every authentication, which covers the mobile app's JWT for free: the api
 * firewall is stateless, so each request re-loads the user through the provider and passes back
 * through here - a token issued before the deactivation stops opening anything without waiting for
 * its own expiry.
 *
 * What it does *not* cover is the fourth way in, an already-open web session: ContextListener
 * refreshes the user from the provider without consulting any user checker (verified against
 * vendor/symfony/security-http/Firewall/ContextListener.php). That path is
 * App\EventSubscriber\InactiveAccountSubscriber's, and it delegates its verdict to this class'
 * isRefused() so the rule itself still lives here alone.
 *
 * The message never says whether the password was right - it is raised before any credential is
 * looked at, and the account's existence is the only thing it reveals to somebody who already
 * knows the login.
 */
class AccountStatusChecker implements UserCheckerInterface
{
    /**
     * Resolved against the "security" translation domain by templates/security/login.html.twig -
     * see translations/security.fr.yaml.
     */
    public const string DEACTIVATED_MESSAGE_KEY = 'accountDeactivatedMessage';

    public function checkPreAuth(UserInterface $user): void
    {
        if ($this->isRefused($user)) {
            throw new CustomUserMessageAccountStatusException(self::DEACTIVATED_MESSAGE_KEY);
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // Nothing to add once the credentials have been checked: the whole rule is a
        // pre-authentication one, and the interface requires both methods.
    }

    /**
     * The rule itself, so the two callers that cannot throw an authentication exception
     * (InactiveAccountSubscriber, LdapCredentialsVerifier's early exit) still read it from here.
     */
    public function isRefused(UserInterface $user): bool
    {
        return $user instanceof User && null !== $user->getInactiveDate();
    }
}
