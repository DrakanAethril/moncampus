<?php

declare(strict_types=1);

namespace App\Enum;

/** The headings of UFA > Configuration > Emails' list - see App\Service\PlatformEmail\PlatformEmailCatalog. */
enum PlatformEmailGroup: string
{
    case Alternance = 'alternance';
    case Account = 'account';
    case Messaging = 'messaging';

    public function labelKey(): string
    {
        return match ($this) {
            self::Alternance => 'platformEmailGroupAlternanceLabel',
            self::Account => 'platformEmailGroupAccountLabel',
            self::Messaging => 'platformEmailGroupMessagingLabel',
        };
    }
}
