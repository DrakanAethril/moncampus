<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * « Quel genre de structure ? » - one choice, never several: the API can say « an association »
 * or « not an association », but not « an association or a public service ».
 */
enum CompanyOrganisationType: string
{
    case Any = 'any';
    case Private = 'private';
    case Association = 'association';
    case PublicService = 'public';

    /** @return array<string, string> */
    public function apiParameters(): array
    {
        return match ($this) {
            self::Any => [],
            self::Private => ['est_association' => 'false', 'est_service_public' => 'false'],
            self::Association => ['est_association' => 'true'],
            self::PublicService => ['est_service_public' => 'true'],
        };
    }

    /**
     * The same question asked of a company already in hand, from its « catégorie juridique » -
     * what « Autour d'une commune » has to do, the API's geographic search knowing no such filter.
     * 92xx are associations; 4xxx and 7xxx are public law (établissements publics, collectivités,
     * État). A company of unknown form passes only « Toutes les structures ».
     */
    public function accepts(?string $legalForm): bool
    {
        if (self::Any === $this) {
            return true;
        }

        if (null === $legalForm || '' === $legalForm) {
            return false;
        }

        $association = str_starts_with($legalForm, '92');
        $public = str_starts_with($legalForm, '4') || str_starts_with($legalForm, '7');

        return match ($this) {
            self::Private => !$association && !$public,
            self::Association => $association,
            self::PublicService => $public,
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Any => 'companyOrganisationTypeAnyLabel',
            self::Private => 'companyOrganisationTypePrivateLabel',
            self::Association => 'companyOrganisationTypeAssociationLabel',
            self::PublicService => 'companyOrganisationTypePublicLabel',
        };
    }
}
