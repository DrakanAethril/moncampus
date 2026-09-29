<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a réalisation professionnelle took place - the first half of the rule that files it in one
 * of the three parts of the synthesis table (App\Service\Portfolio\PortfolioSectionResolver).
 */
enum PortfolioSetting: string
{
    /** « Réalisation en cours de formation » - always the first part of the table. */
    case Training = 'training';

    /** « En milieu professionnel » - the part follows the cursus year of the dates it covers. */
    case Workplace = 'workplace';

    public function labelKey(): string
    {
        return match ($this) {
            self::Training => 'portfolioSettingTrainingLabel',
            self::Workplace => 'portfolioSettingWorkplaceLabel',
        };
    }
}
