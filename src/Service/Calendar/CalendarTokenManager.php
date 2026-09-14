<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mints and rotates the secret an account carries in its iCalendar subscription URLs
 * (App\Entity\User::$calendarToken).
 *
 * **Minted on demand, not on creation**: the token appears the first time somebody is actually
 * shown a subscription link, so nothing is exposed for the fifteen hundred accounts that never open
 * one. That does mean a GET writes a row once in an account's life - deliberately, and only once.
 *
 * Rotating is the whole reason this is a stored column rather than a signature: it is the only
 * gesture that takes a leaked link back, and it takes every subscription made from the old one with
 * it. Nothing here decides *who* may ask - the controller does, on the ordinary access rules.
 */
class CalendarTokenManager
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function tokenFor(User $user): string
    {
        $token = $user->getCalendarToken();

        if (null === $token || '' === $token) {
            return $this->rotate($user);
        }

        return $token;
    }

    public function rotate(User $user): string
    {
        // 32 bytes, the same strength as a magic-link verifier (App\Service\MagicLoginService) -
        // this URL is read without any second factor, by a server that is not the subscriber's.
        $token = bin2hex(random_bytes(32));

        $user->setCalendarToken($token);
        $this->entityManager->flush();

        return $token;
    }
}
