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

    public function labelKey(): string
    {
        return match ($this) {
            self::Ufa => 'laptopLoanTypeUfaLabel',
            self::Cfc => 'laptopLoanTypeCfcLabel',
        };
    }
}
