<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Symfony\Component\Ldap\LdapInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Authenticates against LDAP (bind-based) and mirrors the account into a local
 * App\Entity\User row, created on first login, so the rest of the app can attach
 * relations to a stable Doctrine entity instead of a transient LDAP identity.
 */
class LdapAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly LdapInterface $ldap,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $ldapBaseDn,
        private readonly string $ldapSearchDn,
        #[\SensitiveParameter] private readonly string $ldapSearchPassword,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $username = $request->request->getString('_username');
        $password = $request->request->getString('_password');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $username);

        return new Passport(
            new UserBadge($username, $this->loadOrCreateUser(...)),
            new CustomCredentials($this->verifyPassword(...), $password),
            [
                new CsrfTokenBadge('authenticate', $request->request->getString('_csrf_token')),
                new RememberMeBadge(),
            ],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_home'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate('app_login');
    }

    private function loadOrCreateUser(string $username): User
    {
        $entry = $this->findLdapEntry($username);

        if (null === $entry) {
            throw new UserNotFoundException(\sprintf('No LDAP entry found for uid "%s".', $username));
        }

        $user = $this->userRepository->findOneBy(['username' => $username]) ?? new User($username);
        $user->setEmail(($entry->getAttribute('mail') ?? [])[0] ?? null);
        $user->setDisplayName(($entry->getAttribute('cn') ?? [])[0] ?? null);
        $user->setRoles($this->resolveRoles($entry));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function verifyPassword(string $password, User $user): bool
    {
        $entry = $this->findLdapEntry($user->getUserIdentifier());

        if (null === $entry) {
            return false;
        }

        try {
            $this->ldap->bind($entry->getDn(), $password);
        } catch (ConnectionException) {
            return false;
        }

        return true;
    }

    private function findLdapEntry(string $username): ?Entry
    {
        $this->ldap->bind($this->ldapSearchDn, $this->ldapSearchPassword);

        $escapedUsername = $this->ldap->escape($username, '', LdapInterface::ESCAPE_FILTER);
        $results = $this->ldap->query($this->ldapBaseDn, \sprintf('(uid=%s)', $escapedUsername))->execute();

        return $results[0] ?? null;
    }

    /** @return list<string> */
    private function resolveRoles(Entry $entry): array
    {
        $this->ldap->bind($this->ldapSearchDn, $this->ldapSearchPassword);

        $escapedDn = $this->ldap->escape($entry->getDn(), '', LdapInterface::ESCAPE_FILTER);
        $groups = $this->ldap->query($this->ldapBaseDn, \sprintf('(&(objectClass=groupOfNames)(member=%s))', $escapedDn))->execute();

        $roles = [];
        foreach ($groups as $group) {
            $cn = ($group->getAttribute('cn') ?? [])[0] ?? null;
            if (null !== $cn) {
                $roles[] = 'ROLE_'.strtoupper($cn);
            }
        }

        return $roles;
    }
}
