<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The two bearer secrets an authorisation of the Claude connector hands out (App\Entity\OAuthToken).
 *
 * The prefix is part of the secret the client holds, so a refresh token presented as an access
 * token - or the reverse - is refused on its shape before any lookup.
 */
enum OAuthTokenKind: string
{
    case Access = 'access';
    case Refresh = 'refresh';

    public function prefix(): string
    {
        return match ($this) {
            self::Access => 'mcat',
            self::Refresh => 'mcrt',
        };
    }
}
