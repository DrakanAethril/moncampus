<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The « Mme ☐ M. ☐ » of the ECF cover. Entered on the booklet itself: the platform keeps a civility
 * nowhere else, and the booklet is the only document that asks for one.
 */
enum EcfCivility: string
{
    case Mme = 'mme';
    case M = 'm';

    public function labelKey(): string
    {
        return match ($this) {
            self::Mme => 'ecfCivilityMmeLabel',
            self::M => 'ecfCivilityMLabel',
        };
    }
}
