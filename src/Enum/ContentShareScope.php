<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Who a share is addressed to - see design/validated/content-sharing-between-teachers.md,
 * "Access model".
 *
 * Three values and no fourth: the audience *is* the feature, and it is identical for the five
 * subjects a share can carry. `Group` reaches the named groups **and their descendants**, which is
 * what makes « mon équipe pédagogique » and « toute la section » the same mechanism with two picks;
 * `Catalog` reaches every teacher and personnel of the establishment, and says so on the tin rather
 * than hiding behind a pick of the hierarchy's « campus » root.
 */
enum ContentShareScope: string
{
    case Users = 'users';
    case Group = 'group';
    case Catalog = 'catalog';

    public function labelKey(): string
    {
        return match ($this) {
            self::Users => 'contentShareScopeUsersLabel',
            self::Group => 'contentShareScopeGroupLabel',
            self::Catalog => 'contentShareScopeCatalogLabel',
        };
    }
}
