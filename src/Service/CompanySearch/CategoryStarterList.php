<?php

declare(strict_types=1);

namespace App\Service\CompanySearch;

use App\Entity\CompanySearchCategory;
use App\Enum\CompanyCategoryFlag;
use App\Enum\CompanyCategoryTheme;
use App\Enum\EmployeeBand;

/**
 * « Proposer une liste de départ » - the list of design/validated/vivier-entreprises.md, annexe A,
 * offered only while there is no category at all. A proposal: every line is the administrator's
 * to rename, recode or delete afterwards, and nothing re-creates one they removed.
 */
final class CategoryStarterList
{
    /**
     * @return list<CompanySearchCategory>
     */
    public static function categories(): array
    {
        $definitions = [
            [CompanyCategoryTheme::Digital, 'Services numériques (ESN)', 'Elles réalisent des projets informatiques pour d\'autres entreprises.', ['62.01Z', '62.02A', '62.02B', '62.03Z', '62.09Z'], null, null],
            [CompanyCategoryTheme::Digital, 'Éditeurs de logiciels', 'Elles conçoivent et vendent leurs propres logiciels.', ['58.21Z', '58.29A', '58.29B', '58.29C'], null, null],
            [CompanyCategoryTheme::Digital, 'Hébergement, cloud et données', null, ['63.11Z', '63.12Z'], null, null],
            [CompanyCategoryTheme::Digital, 'Réseaux et télécoms', null, ['61.10Z', '61.20Z', '61.30Z', '61.90Z'], null, null],
            [CompanyCategoryTheme::Digital, 'Matériel, vente et maintenance', 'Revendeurs, dépanneurs et intégrateurs de matériel informatique.', ['46.51Z', '46.52Z', '47.41Z', '95.11Z', '33.20C'], null, null],
            [CompanyCategoryTheme::Business, 'Expertise comptable', null, ['69.20Z'], null, null],
            [CompanyCategoryTheme::Business, 'Banque et assurance', null, ['64.19Z', '65.11Z', '65.12Z', '66.22Z'], null, null],
            [CompanyCategoryTheme::Health, 'Hôpitaux et cliniques', 'Leur service informatique accueille des stagiaires.', ['86.10Z'], null, null],
            [CompanyCategoryTheme::Health, 'Hébergement médico-social', null, ['87.10A', '87.10B', '87.30A'], null, null],
            [CompanyCategoryTheme::Public, 'Collectivités', 'Mairies, communautés de communes, départements, régions.', [], CompanyCategoryFlag::LocalAuthority, null],
            [CompanyCategoryTheme::AllSectors, 'Grandes entreprises, tous secteurs', 'Au moins 250 salariés : elles ont presque toutes un service informatique.', [], null, EmployeeBand::Large],
        ];

        $categories = [];
        $positions = [];
        foreach ($definitions as [$theme, $label, $hint, $codes, $flag, $band]) {
            $positions[$theme->value] = ($positions[$theme->value] ?? -1) + 1;
            $categories[] = (new CompanySearchCategory())
                ->setTheme($theme)
                ->setLabel($label)
                ->setHint($hint)
                ->setNafCodes($codes)
                ->setFlag($flag)
                ->setMinimumBand($band)
                ->setPosition($positions[$theme->value]);
        }

        return $categories;
    }
}
