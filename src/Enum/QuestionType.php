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
 * variants) added on 2026-08-11. Vote (propositions with no right answer, Kahoot's « Poll ») came with
 * the Kahoot import of 2026-11-05: it is played in a live contest only.
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
    case Vote = 'vote';

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
            self::Vote => 'questionTypeVoteLabel',
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
            self::Vote => 'questionTypeVoteShortLabel',
        };
    }

    /**
     * Whether a live concours plays this question. Since the Kahoot import (2026-11-05) the room
     * plays every type a Kahoot carries - one or several right propositions (up to six), a remise en
     * ordre tapped shape by shape, a number on a slider, a word typed, a vote - so what stays out is
     * what a projected board cannot run:
     * - texte à trous, zone, légende and apparier answer *into a support* (blanks, zones, two
     *   columns) the phone does not have, and the board cannot show who placed what;
     * - a calculée asks each student a *different* thing, which a projected board cannot show at all.
     * A launch leaves them out and says so (App\Service\QuizLiveSessionService::createSession()).
     */
    public function isAvailableInLiveContest(): bool
    {
        return !\in_array($this, [self::TexteATrous, self::Zone, self::Legende, self::Apparier, self::Calculee], true);
    }

    /**
     * Whether anything but a live concours plays this question - évaluation, entraînement, a path's
     * quiz, a course's test, a video marker. A vote has no right answer: it is a show of hands in the
     * room, and an attempt would have nothing to mark. Every launch outside the room leaves it out
     * (App\Service\QuizInstantiationService::instantiateQuiz()).
     */
    public function isAvailableOutsideLive(): bool
    {
        return self::Vote !== $this;
    }

    /**
     * Whether the question has a right answer at all - every type but the vote.
     */
    public function hasRightAnswer(): bool
    {
        return self::Vote !== $this;
    }

    /**
     * Whether a quiz tournament's duel plays this question (design/validated/quiz-tournament.md §1,
     * decision 12): the formats answered on a phone in 30 to 40 seconds - QCM, QCM à réponses
     * multiples, vrai/faux, numérique. The tournament draws from ordinary quizzes and leaves the other
     * types out, like the live contest does above; nobody picks them when creating a tournament.
     */
    public function isPlayableInTournament(): bool
    {
        return \in_array($this, [self::Qcm, self::QcmMulti, self::VraiFaux, self::Numerique], true);
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

    // The types that keep no QuizAnswer rows - every one of them carries its whole definition in
    // one of the trait's JSON columns. It used to double as « not playable live »; the two drifted
    // apart with the Kahoot import, when the room learnt to play a numérique and a réponse courte.
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
