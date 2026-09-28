<?php

declare(strict_types=1);

namespace App\Security;

/**
 * What a mobile sign-in or a refresh answers: the hour-long JWT every `/api` call carries, and the
 * refresh token that buys the next one. The refresh token is shown here once and stored hashed.
 */
final readonly class MobileTokenPair
{
    public function __construct(
        public string $token,
        public string $refreshToken,
    ) {
    }

    /** @return array{token: string, refreshToken: string} */
    public function toArray(): array
    {
        return ['token' => $this->token, 'refreshToken' => $this->refreshToken];
    }
}
