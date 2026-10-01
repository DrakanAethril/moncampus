<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The headings the company categories are filed under on the search screen. Fixed in code: they
 * only group the list, and a list of headings an administrator could empty would leave categories
 * with nowhere to go.
 */
enum CompanyCategoryTheme: string
{
    case Digital = 'digital';
    case Business = 'business';
    case Industry = 'industry';
    case Health = 'health';
    case Public = 'public';
    case AllSectors = 'all_sectors';

    public function labelKey(): string
    {
        return match ($this) {
            self::Digital => 'companyCategoryThemeDigitalLabel',
            self::Business => 'companyCategoryThemeBusinessLabel',
            self::Industry => 'companyCategoryThemeIndustryLabel',
            self::Health => 'companyCategoryThemeHealthLabel',
            self::Public => 'companyCategoryThemePublicLabel',
            self::AllSectors => 'companyCategoryThemeAllSectorsLabel',
        };
    }
}
