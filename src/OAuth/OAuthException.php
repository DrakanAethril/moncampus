<?php

declare(strict_types=1);

namespace App\OAuth;

/**
 * An OAuth error as the specification names it (RFC 6749 § 5.2): an `error` code the client acts
 * on, and a description for whoever reads the logs. The description is English and technical - it
 * is read by a developer of the client, never shown to a teacher.
 */
final class OAuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
    ) {
        parent::__construct($description);
    }

    public static function invalidRequest(string $description): self
    {
        return new self('invalid_request', $description);
    }

    public static function invalidGrant(string $description): self
    {
        return new self('invalid_grant', $description);
    }

    public static function invalidClient(string $description): self
    {
        return new self('invalid_client', $description, 401);
    }

    /** @return array{error: string, error_description: string} */
    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
