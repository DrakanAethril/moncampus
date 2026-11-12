<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Entity\WordCloud;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * How a visitor without an account is recognised on a public cloud: a random token in an httpOnly
 * cookie scoped to the cloud's address (/cloud/{code}), of which only the SHA-256 is stored
 * (WordCloudSubmission::$guestTokenHash). Losing the cookie is starting again with a fresh quota -
 * the rate limiter of the public page is what stops a script.
 */
final class WordCloudGuestToken
{
    /** A cloud may stay open for days; the token outlives the usual window. */
    private const string LIFETIME = '+30 days';

    public static function mint(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function cookieName(WordCloud $cloud): string
    {
        return 'word_cloud_guest_'.$cloud->getId();
    }

    public static function cookie(WordCloud $cloud, string $token, bool $secure): Cookie
    {
        return Cookie::create(self::cookieName($cloud))
            ->withValue($token)
            ->withExpires(new \DateTimeImmutable(self::LIFETIME))
            ->withPath('/cloud/'.$cloud->getPublicCode())
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    /** The token this request carries for the cloud, if a well-formed one. */
    public static function fromRequest(Request $request, WordCloud $cloud): ?string
    {
        $token = $request->cookies->get(self::cookieName($cloud));

        return \is_string($token) && 1 === preg_match('/^[0-9a-f]{64}$/', $token) ? $token : null;
    }
}
