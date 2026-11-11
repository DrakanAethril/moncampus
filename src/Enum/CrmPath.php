<?php

declare(strict_types=1);

namespace App\Enum;

/** A first enrolment in the formation, or a re-enrolment (second year, repeated year). */
enum CrmPath: string
{
    case Enrolment = 'enrolment';
    case Reenrolment = 'reenrolment';

    public function labelKey(): string
    {
        return match ($this) {
            self::Enrolment => 'crmPathEnrolmentLabel',
            self::Reenrolment => 'crmPathReenrolmentLabel',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Enrolment => 'Inscription',
            self::Reenrolment => 'Réinscription',
        };
    }
}
