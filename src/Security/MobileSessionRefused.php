<?php

declare(strict_types=1);

namespace App\Security;

/**
 * A refresh token that buys nothing. `$reason` is what the app is told: `invalid_refresh_token`
 * (unknown, expired, revoked, or replayed - the app signs in again either way) or `account_disabled`.
 */
final class MobileSessionRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
