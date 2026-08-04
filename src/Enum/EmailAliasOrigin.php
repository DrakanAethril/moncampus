<?php

namespace App\Enum;

/**
 * D'où vient un App\Entity\EmailAlias - ce qui décide à la fois des règles de forme qu'il doit
 * respecter et de ce qu'une interface a le droit d'en faire.
 *
 * La distinction n'est pas documentaire : la réception étant en catch-all, une partie locale prise
 * l'est pour tout l'établissement. Un alias saisi à la main est donc une identité d'expédition
 * créée de toutes pièces sur le domaine de l'établissement, ce qu'un alias dérivé de l'état civil
 * ou de l'annuaire n'est pas.
 */
enum EmailAliasOrigin: string
{
    /** Composé à partir de l'état civil (`prenom.nom`) par App\Service\StudentMailAddressGenerator. */
    case Generated = 'generated';

    /** L'identifiant LDAP de l'élève (`croux`), repris tel quel - seul cas sans point. */
    case Login = 'login';

    /** Saisi par un humain. Le seul cas soumis à la règle du point, et le seul administrable. */
    case Manual = 'manual';

    /**
     * Un alias dérivé de l'état civil ou de l'annuaire n'est pas modifiable depuis l'application :
     * il suit sa source. Seul le manuel se crée, se désactive et se supprime à la main.
     */
    public function isManageable(): bool
    {
        return self::Manual === $this;
    }

    /**
     * La règle du point ne s'applique qu'au manuel. Le login en est exempté parce qu'il n'est
     * précisément pas saisi : il reprend l'identifiant de l'annuaire, qui n'a jamais de point.
     */
    public function requiresDot(): bool
    {
        return self::Manual === $this;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Generated => 'emailAliasOriginGeneratedLabel',
            self::Login => 'emailAliasOriginLoginLabel',
            self::Manual => 'emailAliasOriginManualLabel',
        };
    }
}
