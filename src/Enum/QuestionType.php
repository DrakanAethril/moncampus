<?php

namespace App\Enum;

/** The 6 question shapes a QuizQuestion can take - see design/design_handoff_quiz/README.md, screens 1b/2a/2b. */
enum QuestionType: string
{
    case Qcm = 'qcm';
    case QcmMulti = 'qcm_multi';
    case VraiFaux = 'vrai_faux';
    case Image = 'image';
    case Ordre = 'ordre';
    case TexteATrous = 'texte_a_trous';

    public function labelKey(): string
    {
        return match ($this) {
            self::Qcm => 'questionTypeQcmLabel',
            self::QcmMulti => 'questionTypeQcmMultiLabel',
            self::VraiFaux => 'questionTypeVraiFauxLabel',
            self::Image => 'questionTypeImageLabel',
            self::Ordre => 'questionTypeOrdreLabel',
            self::TexteATrous => 'questionTypeTexteATrousLabel',
        };
    }

    // Short badge label used in the question bank list (1b: "QCM", "Multi", "V/F", "Image", "Ordre", "Trous").
    public function shortLabelKey(): string
    {
        return match ($this) {
            self::Qcm => 'questionTypeQcmShortLabel',
            self::QcmMulti => 'questionTypeQcmMultiShortLabel',
            self::VraiFaux => 'questionTypeVraiFauxShortLabel',
            self::Image => 'questionTypeImageShortLabel',
            self::Ordre => 'questionTypeOrdreShortLabel',
            self::TexteATrous => 'questionTypeTexteATrousShortLabel',
        };
    }

    /**
     * Whether a live concours can project this question and collect answers with its tap-one-answer
     * device UI. Texte à trous can't: it has no answer options to project and its response is typed
     * (or word-bank placed) per blank, so it is filtered out of the live draw
     * (App\Service\QuizLiveSessionService) and the editor warns about it - see the handoff README's
     * "Exclu du tirage en mode concours live". It stays fully in play for évaluation/entraînement,
     * web and mobile.
     */
    public function isAvailableInLiveContest(): bool
    {
        return self::TexteATrous !== $this;
    }

    // Answer options live in QuizAnswer rows for every type but texte à trous, whose whole
    // definition sits in QuizQuestion::$blanksConfig instead (see App\Entity\QuizQuestionDefinitionTrait).
    public function usesAnswerRows(): bool
    {
        return self::TexteATrous !== $this;
    }
}
