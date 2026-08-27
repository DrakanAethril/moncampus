<?php

declare(strict_types=1);

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
 * email instead of a username/password.
 *
 * A link mailed to an inbox is a weaker proof of identity than an LDAP bind, and what narrows it is
 * therefore **the address it can be sent to**, never the role of the person asking: only
 * User::$contactEmail, and only once its owner has typed it and proved they read it
 * (User::isContactEmailVerified()). See isEligible(), which is the whole of the rule.
 */
class MagicLoginService
{
    private const int TOKEN_TTL_MINUTES = 60;

    // The mobile link is deliberately shorter-lived than the web one: it is meant to be opened on
    // the phone that just asked for it, within the minute (design_handoff_mobile, principe 7 -
    // "lien à usage unique, valable 15 min").
    private const int MOBILE_TOKEN_TTL_MINUTES = 15;

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
        $token = $this->issueToken($user, $requestIp, self::TOKEN_TTL_MINUTES);

        if (null === $token) {
            return;
        }

        $this->mailer->send((new TemplatedEmail())
            ->to($user->getContactEmail())
            ->subject($this->translator->trans('magicLoginEmailSubject'))
            ->htmlTemplate('emails/magic_login.html.twig')
            ->context(['user' => $user, 'token' => $token]));
    }

    /**
     * Same flow for the mobile app (design_handoff_mobile, screens 6a-6c), with two differences:
     * the mail carries a deep link that opens the app rather than a web URL, and the token only
     * lives 15 minutes.
     *
     * Silently a no-op for the same reason as requestLink(): the app always shows "lien envoyé",
     * whether or not the address belongs to an eligible account.
     */
    public function requestMobileLink(?User $user, ?string $requestIp): void
    {
        $token = $this->issueToken($user, $requestIp, self::MOBILE_TOKEN_TTL_MINUTES);

        if (null === $token) {
            return;
        }

        $this->mailer->send((new TemplatedEmail())
            ->to($user->getContactEmail())
            ->subject($this->translator->trans('magicLoginEmailSubject'))
            ->htmlTemplate('emails/magic_login_mobile.html.twig')
            ->context([
                'user' => $user,
                'token' => $token,
                'minutes' => self::MOBILE_TOKEN_TTL_MINUTES,
            ]));
    }

    /**
     * Issues (and stores) a single-use token, or null when there is nobody eligible to issue one
     * for. Any pending token of that user is dropped first: asking for a new link invalidates the
     * previous one.
     */
    private function issueToken(?User $user, ?string $requestIp, int $ttlMinutes): ?string
    {
        if (null === $user || !$this->isEligible($user)) {
            return null;
        }

        $this->tokenRepository->deletePendingForUser($user);

        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));

        $token = new MagicLoginToken(
            $user,
            $selector,
            hash('sha256', $verifier),
            new \DateTimeImmutable('+'.$ttlMinutes.' minutes'),
            $requestIp,
        );

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $selector.'.'.$verifier;
    }

    // Resolves and consumes a mailed link's token in one atomic step - returns the now-logged-in
    // User, or null for any reason it can't proceed (malformed, unknown selector, wrong verifier,
    // expired, already used, or no longer eligible - inactivated, or the contact address unconfirmed
    // since the link was sent). Never distinguishes these cases to the caller, same "don't leak why"
    // principle as requestLink().
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

    /**
     * Two conditions, and **no role condition at all**.
     *
     * ROLE_ADMIN was excluded here until 2026-08-27, on the grounds that a mailed link is a weaker
     * proof than an LDAP bind and that administrators are where that gap matters most. It was
     * removed on request: the exclusion did not make an administrator's account harder to reach, it
     * only left the people who cannot reset their own password with no way back in at all - the
     * administration reset was itself removed on 2026-08-24, so the answer for them was `samba-tool`
     * on the domain controller, which is not an answer somebody locked out at 8am has.
     *
     * What still narrows the path is the address rather than the role, and it is not nothing: the
     * link goes only to a contact address its owner typed and then proved they read, it is
     * single-use, it expires in an hour (a quarter of one on mobile), and asking for one is rate
     * limited on both the address and the requesting IP.
     *
     * Checked both when issuing a link (requestLink()) and again when consuming one (consume()),
     * since an account can be inactivated or lose its confirmed address in between.
     */
    private function isEligible(User $user): bool
    {
        return null === $user->getInactiveDate() && $user->isContactEmailVerified();
    }
}
