<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which paper convention a laptop loan is printed on: the CFA Aspect Aquitaine model for an
 * apprentice (UFA), the Beaupeyrat one for a continuing-education student (CFC).
 *
 * Carried by the loan rather than derived from the borrower on purpose - it pins the model chosen
 * when the loan was recorded, so a borrower who later changes programme keeps printing on the
 * document that was actually signed.
 */
enum LaptopLoanType: string
{
    case Ufa = 'ufa';
    case Cfc = 'cfc';

    /**
     * The type a borrower's situation calls for: an apprentice borrows under the UFA convention,
     * anyone else under the CFC one. What makes a borrower an apprentice is being tagged with the
     * alternance Modality in one of their programmes (Modality::$isAlternance), not merely being
     * enrolled in a programme that runs an alternance track - see
     * ProgramStudentModalityRepository::findAlternanceProgramIdsForStudent().
     *
     * This only ever pre-selects the field. The operator has the last word, because the paper that
     * gets signed is a decision, not a lookup: a borrower with no modality recorded yet, or one
     * whose situation is changing, still has to be lent a machine today.
     */
    public static function forAlternance(bool $isAlternant): self
    {
        return $isAlternant ? self::Ufa : self::Cfc;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Ufa => 'laptopLoanTypeUfaLabel',
            self::Cfc => 'laptopLoanTypeCfcLabel',
        };
    }
}
