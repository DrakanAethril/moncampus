<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The kind of national certification a Program prepares, as it is written on the fiche's title
 * line ("TP - Administrateur d'Infrastructures Sécurisées").
 *
 * Only the abbreviation is stored; the full French wording lives in the translations, so a
 * document can print either.
 */
enum CertificationKind: string
{
    case TitrePro = 'titre_pro';
    case Bts = 'bts';
    case Bachelor = 'bachelor';
    case Licence = 'licence';
    case Master = 'master';
    case Other = 'other';

    /** Short prefix printed ahead of the certification label, "TP" in "TP - Administrateur …". */
    public function abbreviation(): string
    {
        return match ($this) {
            self::TitrePro => 'TP',
            self::Bts => 'BTS',
            self::Bachelor => 'Bachelor',
            self::Licence => 'Licence',
            self::Master => 'Master',
            self::Other => '',
        };
    }

    public function translationKey(): string
    {
        return 'certificationKind'.ucfirst(str_replace('_', '', ucwords($this->value, '_'))).'Label';
    }
}
