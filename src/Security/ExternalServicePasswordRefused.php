<?php

declare(strict_types=1);

namespace App\Security;

/**
 * A password App\Security\ExternalServicePasswords would not accept for a service - too weak, the
 * establishment password itself, or unverifiable. Carries a translation key.
 */
final class ExternalServicePasswordRefused extends \RuntimeException
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(public readonly string $messageKey, public readonly array $parameters = [])
    {
        parent::__construct($messageKey);
    }
}
