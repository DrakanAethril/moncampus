<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The 12 question shapes a QuizQuestion can take - see design/design_handoff_quiz/README.md,
 * screens 1b/2a/2b, plus the two "zones" types of the étude 2026-08-11: Zone (click the right
 * zone(s) of a support) and Legende (place each label on its zone), Apparier (relate the items of
 * two columns), and the numeric pair added on 2026-08-11: Numerique (a number, within a tolerance,
 * optionally with its unit) and Calculee (the same, over variables drawn per student and an answer
 * given by a formula), and ReponseCourte (type a word or a phrase, matched against the accepted
 * variants) added on 2026-08-11.
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
    case Apparier = 'apparier';
    case Numerique = 'numerique';
    case Calculee = 'calculee';
    case ReponseCourte = 'reponse_courte';

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
            self::Apparier => 'questionTypeApparierLabel',
            self::Numerique => 'questionTypeNumeriqueLabel',
            self::Calculee => 'questionTypeCalculeeLabel',
            self::ReponseCourte => 'questionTypeReponseCourteLabel',
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
            self::Apparier => 'questionTypeApparierShortLabel',
            self::Numerique => 'questionTypeNumeriqueShortLabel',
            self::Calculee => 'questionTypeCalculeeShortLabel',
            self::ReponseCourte => 'questionTypeReponseCourteShortLabel',
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
        // tappable options, the answer is a click into (or labels placed onto) a support. Apparier
        // is the same problem again: the answer is N associations, not one tap. The numeric pair
        // adds a third reason on top - a calculée question asks each student a *different* thing,
        // which a projected board cannot show at all.
        return !\in_array($this, self::CONFIG_DRIVEN, true);
    }

    // Answer options live in QuizAnswer rows for every type but the config-driven ones, whose whole
    // definition sits in the trait's JSON columns instead (App\Entity\QuizQuestionDefinitionTrait).
    public function usesAnswerRows(): bool
    {
        return !\in_array($this, self::CONFIG_DRIVEN, true);
    }

    /**
     * The two types whose answer is *typed text matched against accepted variants*: a texte à trous
     * does it once per blank, a réponse courte does it once for the whole question.
     *
     * They share the blanks config and the blanks grading wholesale - a short answer is stored as a
     * single blank, and everything from App\Util\BlankTextParser::matches() (case and accent
     * folding, the optional one-character typo tolerance) to the per-blank verdicts already applied
     * to it. Writing a second comparison for "is this word the right word" is exactly the
     * duplication this module already documents around QuizAttemptGrader.
     */
    public function usesBlankAnswers(): bool
    {
        return \in_array($this, [self::TexteATrous, self::ReponseCourte], true);
    }

    // The two types whose definition is the zone config JSON - the support, its zones and what
    // the student is expected to do with them (App\Entity\QuizQuestionDefinitionTrait).
    public function usesZoneConfig(): bool
    {
        return \in_array($this, [self::Zone, self::Legende], true);
    }

    // The type whose definition is the matching config JSON - the two columns, their pairs and the
    // extra right-hand items that match nothing (App\Entity\QuizQuestionDefinitionTrait).
    public function usesMatchingConfig(): bool
    {
        return self::Apparier === $this;
    }

    // The two types whose definition is the numeric config JSON - the expected value or the formula
    // that produces it, the tolerance and the unit (App\Entity\QuizQuestionDefinitionTrait).
    public function usesNumericConfig(): bool
    {
        return \in_array($this, [self::Numerique, self::Calculee], true);
    }

    // Only a calculée draws variables per student and reads its answer from a formula; a plain
    // numérique has one expected value written by the teacher.
    public function usesFormula(): bool
    {
        return self::Calculee === $this;
    }

    /**
     * The types that keep no QuizAnswer rows and cannot be projected in a live contest - every one
     * of them carries its whole definition in one of the trait's JSON columns. Listed once because
     * the two questions above always had the same answer, and drifted apart twice while they were
     * written out separately.
     */
    private const array CONFIG_DRIVEN = [
        self::TexteATrous,
        self::Zone,
        self::Legende,
        self::Apparier,
        self::Numerique,
        self::Calculee,
        self::ReponseCourte,
    ];
}
