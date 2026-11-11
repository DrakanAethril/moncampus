<?php

declare(strict_types=1);

namespace App\Enum;

/** The three stages a dossier goes through, in this order; a piece belongs to one of them. */
enum CrmPhase: string
{
    case PreEnrolment = 'pre_enrolment';
    case Enrolment = 'enrolment';
    case Documents = 'documents';

    public function labelKey(): string
    {
        return match ($this) {
            self::PreEnrolment => 'crmPhasePreEnrolmentLabel',
            self::Enrolment => 'crmPhaseEnrolmentLabel',
            self::Documents => 'crmPhaseDocumentsLabel',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PreEnrolment => 'Pré-inscription',
            self::Enrolment => 'Inscription',
            self::Documents => 'Documents',
        };
    }

    public function step(): CrmStep
    {
        return match ($this) {
            self::PreEnrolment => CrmStep::PreEnrolment,
            self::Enrolment => CrmStep::Enrolment,
            self::Documents => CrmStep::Documents,
        };
    }
}
