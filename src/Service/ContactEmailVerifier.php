<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Owns the "prove you can read this inbox" flow for User::$contactEmail - called from
 * ProfileController (self-service, web and mobile) and its resend action, where an address is
 * never marked verified without an actual click-through by whoever controls that inbox.
 *
 * DirectoryUserController::edit() (staff editing another user's profile) and
 * DirectoryUserController::new() (set at account creation) are the deliberate exception: a
 * staff/admin/staff-lead typing in a contact email on someone else's behalf is trusted outright
 * (see markVerifiedByStaff()) - no confirmation mail is sent for those two paths.
 */
class ContactEmailVerifier
{
    private const int TOKEN_TTL_HOURS = 24;
    public const int RESEND_COOLDOWN_MINUTES = 2;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly UserRepository $userRepository,
    ) {
    }

    // Generates a fresh token and (re)sends the confirmation mail to $pendingContactEmail - call
    // whenever it's just been set to a new non-null value (including a first-time resend).
    // Deliberately never touches $contactEmail/$contactEmailVerifiedAt: the previously confirmed
    // address (if any) stays active until this pending one is actually confirmed (see
    // confirmByToken()).
    public function requestVerification(User $user): void
    {
        $token = bin2hex(random_bytes(32));

        $user
            ->setContactEmailToken($token)
            ->setContactEmailTokenRequestedAt(new \DateTimeImmutable())
        ;

        $this->mailer->send((new TemplatedEmail())
            ->to($user->getPendingContactEmail())
            ->subject($this->translator->trans('contactEmailConfirmationEmailSubject'))
            ->htmlTemplate('emails/contact_email_confirmation.html.twig')
            ->context(['user' => $user, 'token' => $token]));
    }

    // Staff/admin/staff-lead setting or changing a contact email on someone else's behalf (user
    // creation, user management) skips the click-through entirely - see this class's docblock for
    // why that's a deliberate exception rather than an oversight. Call instead of
    // requestVerification() from those two paths only.
    public function markVerifiedByStaff(User $user): void
    {
        $user
            ->setContactEmailVerifiedAt(new \DateTimeImmutable())
            ->setContactEmailToken(null)
            ->setContactEmailTokenRequestedAt(null)
        ;
    }

    // True once RESEND_COOLDOWN_MINUTES has elapsed since the last token was requested - guards
    // ProfileController's resend action against being hammered (every route reaching this is
    // already ROLE_USER-gated, so this is just spam prevention, not abuse prevention).
    public function canResend(User $user): bool
    {
        $requestedAt = $user->getContactEmailTokenRequestedAt();

        return null === $requestedAt || $requestedAt <= new \DateTimeImmutable('-'.self::RESEND_COOLDOWN_MINUTES.' minutes');
    }

    // A global lookup (contact_email_token is a unique column) rather than scoped to an
    // already-known User, since the whole point (App\Controller\PublicContactEmailController) is
    // this can be reached by someone who isn't logged in yet at all - there is no "current user"
    // to check the token against. Never mutates anything - the GET landing page uses this to
    // decide which state to show (pending/expired/invalid) without side effects, matching the
    // magic-link login pattern's own GET-renders/POST-consumes split (see App\Security\
    // MagicLinkAuthenticator's docblock).
    public function findPendingUserForToken(string $token): ?User
    {
        return $this->userRepository->findOneBy(['contactEmailToken' => $token]);
    }

    // TOKEN_TTL_HOURS check, extracted so the GET peek (findPendingUserForToken() callers) and the
    // POST confirmByToken() share one source of truth for the expiry rule.
    public function isPendingTokenExpired(User $user): bool
    {
        $requestedAt = $user->getContactEmailTokenRequestedAt();

        return null === $requestedAt || $requestedAt <= new \DateTimeImmutable('-'.self::TOKEN_TTL_HOURS.' hours');
    }

    // Resolves and confirms a mailed token in one step - called only from the POST confirm action
    // (App\Controller\PublicContactEmailController::confirmSubmit()), never from the GET landing
    // page, so a mail client's/gateway's automatic link-prefetch (a plain GET) can never confirm
    // the address or log anyone in by itself. Returns null, without mutating anything, for an
    // unknown/expired token so the caller can show the right state without learning why it failed.
    // Promotes $pendingContactEmail onto $contactEmail here - this is the only place that happens.
    public function confirmByToken(string $token): ?User
    {
        $user = $this->findPendingUserForToken($token);

        if (null === $user || $this->isPendingTokenExpired($user)) {
            return null;
        }

        $user
            ->setContactEmail($user->getPendingContactEmail())
            ->setContactEmailVerifiedAt(new \DateTimeImmutable())
            ->setPendingContactEmail(null)
            ->setContactEmailToken(null)
            ->setContactEmailTokenRequestedAt(null)
        ;

        return $user;
    }
}
