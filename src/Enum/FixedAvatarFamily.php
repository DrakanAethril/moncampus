<?php

declare(strict_types=1);

namespace App\Enum;

/** The three families of the ménagerie (App\Service\FixedAvatars), headings of the profile's picker. */
enum FixedAvatarFamily: string
{
    case Monster = 'monster';
    case Animal = 'animal';
    case Nature = 'nature';

    public function labelKey(): string
    {
        return match ($this) {
            self::Monster => 'fixedAvatarFamilyMonster',
            self::Animal => 'fixedAvatarFamilyAnimal',
            self::Nature => 'fixedAvatarFamilyNature',
        };
    }
}
