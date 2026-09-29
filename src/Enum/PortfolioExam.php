<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The two examinations a portfolio is deposited for. Each has its own dossier, its own deadline and
 * its own conformity check (annexe VI-2 for the E5, VII-2 for the E6).
 */
enum PortfolioExam: string
{
    case E5 = 'e5';
    case E6 = 'e6';

    public function labelKey(): string
    {
        return match ($this) {
            self::E5 => 'portfolioExamE5Label',
            self::E6 => 'portfolioExamE6Label',
        };
    }

    /**
     * The items of the conformity check the équipe pédagogique ticks before endorsing a deposit -
     * the reasons annexes VI-2 and VII-2 list for declaring a dossier non-conforming.
     *
     * @return list<string> translation keys, also the keys stored in PortfolioDeposit::$checklist
     */
    public function checklistKeys(): array
    {
        return match ($this) {
            self::E5 => [
                'portfolioChecklistFilePresent',
                'portfolioChecklistInTime',
                'portfolioChecklistInternshipDuration',
                'portfolioChecklistAttestationsSigned',
            ],
            self::E6 => [
                'portfolioChecklistFilePresent',
                'portfolioChecklistInTime',
            ],
        };
    }
}
