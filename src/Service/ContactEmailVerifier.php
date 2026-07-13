<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Owns the "prove you can read this inbox" flow for User::$contactEmail - called from both
 * ProfileController (self-service) and UserManagementController (staff editing another user's
 * profile) so an address is never marked verified without an actual click-through by whoever
 * controls that inbox, regardless of who typed it in.
 */
class ContactEmailVerifier
{
    private const int TOKEN_TTL_HOURS = 24;
    public const int RESEND_COOLDOWN_MINUTES = 2;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
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

    // True once RESEND_COOLDOWN_MINUTES has elapsed since the last token was requested - guards
    // ProfileController's resend action against being hammered (every route reaching this is
    // already ROLE_USER-gated, so this is just spam prevention, not abuse prevention).
    public function canResend(User $user): bool
    {
        $requestedAt = $user->getContactEmailTokenRequestedAt();

        return null === $requestedAt || $requestedAt <= new \DateTimeImmutable('-'.self::RESEND_COOLDOWN_MINUTES.' minutes');
    }

    // Confirms $token against $user's own pending token (never a global lookup) - returns false,
    // without mutating anything, when it doesn't match or has expired so the caller can flash the
    // right message.
    public function confirm(User $user, string $token): bool
    {
        $pendingToken = $user->getContactEmailToken();
        $requestedAt = $user->getContactEmailTokenRequestedAt();

        if (null === $pendingToken || null === $requestedAt || !hash_equals($pendingToken, $token)) {
            return false;
        }

        if ($requestedAt <= new \DateTimeImmutable('-'.self::TOKEN_TTL_HOURS.' hours')) {
            return false;
        }

        $user
            ->setContactEmailVerifiedAt(new \DateTimeImmutable())
            ->setContactEmailToken(null)
        ;

        return true;
    }
}
