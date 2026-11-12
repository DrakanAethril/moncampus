<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Entity\User;

/**
 * Who writes a word: an account, or a visitor of a public cloud known by the hash of its token
 * (WordCloudGuestToken). Exactly one of the two.
 */
final readonly class WordCloudAuthor
{
    private function __construct(
        public ?User $user,
        public ?string $guestTokenHash,
    ) {
    }

    public static function account(User $user): self
    {
        return new self($user, null);
    }

    public static function guest(string $tokenHash): self
    {
        return new self(null, $tokenHash);
    }
}
