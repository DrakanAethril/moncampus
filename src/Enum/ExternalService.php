<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * An outside service a person may open their account to - with a password of its own
 * (App\Entity\ExternalServicePassword), never the establishment password.
 *
 * The establishment password is the directory's: it opens the workstations, the Wi-Fi, the
 * mailboxes, the internal resources. A service outside the establishment, however trustworthy, is
 * not where that password is typed or relied on. Each case here names what a service needs to exist
 * for somebody: the feature it belongs to.
 */
enum ExternalService: string
{
    case ClaudeConnector = 'claude_connector';

    public function feature(): Feature
    {
        return match ($this) {
            self::ClaudeConnector => Feature::ClaudeConnector,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::ClaudeConnector => 'externalServiceClaudeConnectorLabel',
        };
    }
}
