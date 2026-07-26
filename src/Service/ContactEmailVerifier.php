<?php

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
 * UserManagementController (staff editing another user's profile) and
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

    // Generates a fresh token and (re)sends the confirmation mail to the current $contactEmail -
    // call whenever it's just been set to a new non-null value (including a first-time resend).
    public function requestVerification(User $user): void
    {
        $token = bin2hex(random_bytes(32));

        $user
            ->setContactEmailToken($token)
            ->setContactEmailTokenRequestedAt(new \DateTimeImmutable())
            ->setContactEmailVerifiedAt(null)
        ;

        $this->mailer->send((new TemplatedEmail())
            ->to($user->getContactEmail())
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

    // Resolves and confirms a mailed token in one step - a global lookup (contact_email_token is
    // a unique column) rather than scoped to an already-known User, since the whole point
    // (App\Controller\PublicContactEmailController) is this can be reached by someone who isn't
    // logged in yet at all - there is no "current user" to check the token against. Returns null,
    // without mutating anything, for an unknown/expired token so the caller can flash the right
    // message without learning why it failed.
    public function confirmByToken(string $token): ?User
    {
        $user = $this->userRepository->findOneBy(['contactEmailToken' => $token]);
        $requestedAt = $user?->getContactEmailTokenRequestedAt();

        if (null === $user || null === $requestedAt) {
            return null;
        }

        if ($requestedAt <= new \DateTimeImmutable('-'.self::TOKEN_TTL_HOURS.' hours')) {
            return null;
        }

        $user
            ->setContactEmailVerifiedAt(new \DateTimeImmutable())
            ->setContactEmailToken(null)
        ;

        return $user;
    }
}
