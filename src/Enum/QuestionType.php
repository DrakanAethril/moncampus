<?php

namespace App\Enum;

/** The 5 question shapes a QuizQuestion can take - see design/design_campus_manager/reference/Générateur de quiz.dc.html, screen 1b. */
enum QuestionType: string
{
    case Qcm = 'qcm';
    case QcmMulti = 'qcm_multi';
    case VraiFaux = 'vrai_faux';
    case Image = 'image';
    case Ordre = 'ordre';

    public function labelKey(): string
    {
        return match ($this) {
            self::Qcm => 'questionTypeQcmLabel',
            self::QcmMulti => 'questionTypeQcmMultiLabel',
            self::VraiFaux => 'questionTypeVraiFauxLabel',
            self::Image => 'questionTypeImageLabel',
            self::Ordre => 'questionTypeOrdreLabel',
        };
    }

    // Short badge label used in the question bank list (1b: "QCM", "Multi", "V/F", "Image", "Ordre").
    public function shortLabelKey(): string
    {
        return match ($this) {
            self::Qcm => 'questionTypeQcmShortLabel',
            self::QcmMulti => 'questionTypeQcmMultiShortLabel',
            self::VraiFaux => 'questionTypeVraiFauxShortLabel',
            self::Image => 'questionTypeImageShortLabel',
            self::Ordre => 'questionTypeOrdreShortLabel',
        };
    }
}
