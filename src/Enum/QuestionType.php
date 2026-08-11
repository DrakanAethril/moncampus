<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The 8 question shapes a QuizQuestion can take - see design/design_handoff_quiz/README.md,
 * screens 1b/2a/2b, plus the two "zones" types of the étude 2026-08-11: Zone (click the right
 * zone(s) of a support) and Legende (place each label on its zone).
 */
enum QuestionType: string
{
    case Qcm = 'qcm';
    case QcmMulti = 'qcm_multi';
    case VraiFaux = 'vrai_faux';
    case Image = 'image';
    case Ordre = 'ordre';
    case TexteATrous = 'texte_a_trous';
    case Zone = 'zone';
    case Legende = 'legende';

    public function labelKey(): string
    {
        return match ($this) {
            self::Qcm => 'questionTypeQcmLabel',
            self::QcmMulti => 'questionTypeQcmMultiLabel',
            self::VraiFaux => 'questionTypeVraiFauxLabel',
            self::Image => 'questionTypeImageLabel',
            self::Ordre => 'questionTypeOrdreLabel',
            self::TexteATrous => 'questionTypeTexteATrousLabel',
            self::Zone => 'questionTypeZoneLabel',
            self::Legende => 'questionTypeLegendeLabel',
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
            self::Zone => 'questionTypeZoneShortLabel',
            self::Legende => 'questionTypeLegendeShortLabel',
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
        // Zone/Legende share the texte à trous problem exactly: nothing to project as four
        // tappable options, the answer is a click into (or labels placed onto) a support.
        return !\in_array($this, [self::TexteATrous, self::Zone, self::Legende], true);
    }

    // Answer options live in QuizAnswer rows for every type but texte à trous and the two zones
    // types, whose whole definition sits in the trait's JSON columns instead
    // (see App\Entity\QuizQuestionDefinitionTrait).
    public function usesAnswerRows(): bool
    {
        return !\in_array($this, [self::TexteATrous, self::Zone, self::Legende], true);
    }

    // The two types whose definition is the zone config JSON - the support, its zones and what
    // the student is expected to do with them (App\Entity\QuizQuestionDefinitionTrait).
    public function usesZoneConfig(): bool
    {
        return \in_array($this, [self::Zone, self::Legende], true);
    }
}
