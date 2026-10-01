<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A category the Recherche d'entreprises API recognises by a flag rather than by activity codes:
 * a town hall is a collectivity whatever its NAF code says.
 */
enum CompanyCategoryFlag: string
{
    case LocalAuthority = 'local_authority';
    case PublicService = 'public_service';

    /** The API parameter that carries it, set to `true`. */
    public function apiParameter(): string
    {
        return match ($this) {
            self::LocalAuthority => 'est_collectivite_territoriale',
            self::PublicService => 'est_service_public',
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::LocalAuthority => 'companyCategoryFlagLocalAuthorityLabel',
            self::PublicService => 'companyCategoryFlagPublicServiceLabel',
        };
    }
}
