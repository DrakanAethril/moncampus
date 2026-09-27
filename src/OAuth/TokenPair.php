<?php

declare(strict_types=1);

namespace App\OAuth;

/**
 * What the token endpoint answers: the two secrets, shown once and never stored in clear.
 */
final readonly class TokenPair
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn,
        public string $scope,
    ) {
    }

    /** @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string} */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->expiresIn,
            'refresh_token' => $this->refreshToken,
            'scope' => $this->scope,
        ];
    }
}
