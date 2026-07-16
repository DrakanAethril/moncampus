<?php

namespace App\Service;

use App\Entity\MagicLoginToken;
use App\Entity\User;
use App\Repository\MagicLoginTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Owns the passwordless "magic link" login flow (App\Security\MagicLinkAuthenticator,
 * App\Controller\PublicMagicLoginController): request a single-use, short-lived login link by
 * email instead of a username/password. Deliberately narrower than the LDAP login it sits next
 * to - see isEligible() - since a link mailed to an inbox is inherently a weaker proof of
 * identity than an LDAP bind, and this must never become a way to sidestep LDAP auth for
 * high-privilege accounts.
 */
class MagicLoginService
{
    private const int TOKEN_TTL_MINUTES = 60;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MagicLoginTokenRepository $tokenRepository,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // No-ops (silently) whenever $user is null or ineligible - callers must always show the same
    // generic "if an account exists, a link was sent" message either way, so this never leaks
    // whether a given contact email is registered (see PublicMagicLoginController).
    public function requestLink(?User $user, ?string $requestIp): void
    {
        if (null === $user || !$this->isEligible($user)) {
            return;
        }

        $this->tokenRepository->deletePendingForUser($user);

        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));

        $token = new MagicLoginToken(
            $user,
            $selector,
            hash('sha256', $verifier),
            new \DateTimeImmutable('+'.self::TOKEN_TTL_MINUTES.' minutes'),
            $requestIp,
        );

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        $this->mailer->send((new TemplatedEmail())
            ->to($user->getContactEmail())
            ->subject($this->translator->trans('magicLoginEmailSubject'))
            ->htmlTemplate('emails/magic_login.html.twig')
            ->context(['user' => $user, 'token' => $selector.'.'.$verifier]));
    }

    // Resolves and consumes a mailed link's token in one atomic step - returns the now-logged-in
    // User, or null for any reason it can't proceed (malformed, unknown selector, wrong verifier,
    // expired, already used, or no longer eligible - e.g. promoted to ROLE_ADMIN since the link
    // was sent). Never distinguishes these cases to the caller, same "don't leak why" principle
    // as requestLink().
    public function consume(string $token, ?string $requestIp): ?User
    {
        if (!str_contains($token, '.')) {
            return null;
        }

        [$selector, $verifier] = explode('.', $token, 2);

        $magicLoginToken = $this->tokenRepository->findOneBySelector($selector);

        if (null === $magicLoginToken || $magicLoginToken->isUsed() || $magicLoginToken->isExpired()) {
            return null;
        }

        if (!hash_equals($magicLoginToken->getVerifierHash(), hash('sha256', $verifier))) {
            return null;
        }

        $user = $magicLoginToken->getUser();

        if (!$this->isEligible($user)) {
            return null;
        }

        // Atomic "first request wins" guard against a link being followed twice in a tight race
        // (double-click, or a corporate scanner racing the real user) - markUsed() itself checks
        // used_at IS NULL, so a second caller here loses even though the checks above passed.
        if (!$this->tokenRepository->markUsed($magicLoginToken)) {
            return null;
        }

        return $user;
    }

    // ROLE_ADMIN accounts must always go through LDAP - a magic link mailed to a contact address
    // is a strictly weaker proof of identity, and admin accounts are exactly the ones where that
    // gap matters most. Checked both when issuing a link (requestLink()) and again when consuming
    // one (consume()), since roles can change in between.
    private function isEligible(User $user): bool
    {
        return null === $user->getInactiveDate()
            && $user->isContactEmailVerified()
            && !\in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
