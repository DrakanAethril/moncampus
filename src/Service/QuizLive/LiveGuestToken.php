<?php

declare(strict_types=1);

namespace App\Service\QuizLive;

use App\Entity\QuizLiveSession;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * How a guest's browser is recognised in a public contest: a random token in an httpOnly cookie
 * scoped to the contest's address (/live/{code}), of which only the SHA-256 is stored
 * (QuizLiveParticipant::$guestTokenHash). No account, no session needed: losing the cookie is
 * losing the seat, and entering again under a new pseudonym.
 */
final class LiveGuestToken
{
    /** Long enough for a whole school day; nothing ends a contest's results with it. */
    private const string LIFETIME = '+1 day';

    public static function mint(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function cookieName(QuizLiveSession $session): string
    {
        return 'live_guest_'.$session->getId();
    }

    public static function cookie(QuizLiveSession $session, string $token, bool $secure): Cookie
    {
        return Cookie::create(self::cookieName($session))
            ->withValue($token)
            ->withExpires(new \DateTimeImmutable(self::LIFETIME))
            ->withPath('/live/'.$session->getRoomCode())
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    /** The hash of the token this request carries for the contest, if any. */
    public static function fromRequest(Request $request, QuizLiveSession $session): ?string
    {
        $token = $request->cookies->get(self::cookieName($session));

        return \is_string($token) && 1 === preg_match('/^[0-9a-f]{64}$/', $token) ? self::hash($token) : null;
    }
}
