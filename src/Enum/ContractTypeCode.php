<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Identifies one of the two fixed UFA contract types (no create/rename UI - see ContractType's
 * docblock) - matched by this code rather than by ContractType's row id, so the seeding
 * migration and every call site agree on which row is which without a lookup-by-label.
 */
enum ContractTypeCode: string
{
    case Apprentissage = 'apprentissage';
    case Professionnalisation = 'professionnalisation';

    public function labelKey(): string
    {
        return match ($this) {
            self::Apprentissage => 'contractTypeApprentissageLabel',
            self::Professionnalisation => 'contractTypeProfessionnalisationLabel',
        };
    }
}
