<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The five kinds of line a survey holds - see design/validated/surveys.md §5.
 *
 * Two of them are not what the word "question" suggests, and both exist because a real survey
 * needs them: Commentaire carries no proposed answers at all (free text), and Titre is not a
 * question but an intertitle, a line in the ordering and nothing else.
 *
 * The three methods below are the single point of truth for the three questions the rest of the
 * feature keeps asking. In particular isAnswerable() is what keeps Titre out of the five separate
 * counts listed in §7.13 - "12 questions · environ 5 minutes", "Question 2 sur 12", the Q1/Q2/Q3
 * numbering, the per-question response rate, and the wave comparison. None of them recounts on its
 * own: they all go through SurveyCampaign::answerableQuestions().
 *
 * There is deliberately no "Échelle" case: an ordered scale is a Unique question carrying the
 * is_scale flag, which declares that the answers' order_index *is* a value (§12.A). A separate
 * type would duplicate the single choice up to the point where the two diverge.
 */
enum SurveyQuestionType: string
{
    case Unique = 'unique';
    case Multiple = 'multiple';
    case Ordre = 'ordre';
    case Commentaire = 'commentaire';
    case Titre = 'titre';

    /**
     * The types offered in the editor, in the mockup's order.
     *
     * @return list<self>
     */
    public static function forEditor(): array
    {
        return [self::Unique, self::Multiple, self::Ordre, self::Commentaire, self::Titre];
    }

    /** Does it carry proposed answers (SurveyAnswer rows)? */
    public function hasAnswers(): bool
    {
        return \in_array($this, [self::Unique, self::Multiple, self::Ordre], true);
    }

    /** Does it expect an answer from the respondent? Titre is the only case that does not. */
    public function isAnswerable(): bool
    {
        return self::Titre !== $this;
    }

    /**
     * Does it take part in the wave comparison? Only the types with proposed answers do: two lists
     * of verbatims put side by side do not subtract (§7.14), and an intertitle has nothing to
     * align.
     */
    public function isComparable(): bool
    {
        return $this->hasAnswers();
    }

    /** Is the "Ces réponses forment une échelle" flag meaningful here? */
    public function supportsScale(): bool
    {
        return self::Unique === $this;
    }

    /** Are min/max_choices meaningful here? */
    public function supportsChoiceBounds(): bool
    {
        return self::Multiple === $this;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Unique => 'surveyQuestionTypeUniqueLabel',
            self::Multiple => 'surveyQuestionTypeMultipleLabel',
            self::Ordre => 'surveyQuestionTypeOrdreLabel',
            self::Commentaire => 'surveyQuestionTypeCommentaireLabel',
            self::Titre => 'surveyQuestionTypeTitreLabel',
        };
    }

    public function hintKey(): string
    {
        return match ($this) {
            self::Unique => 'surveyQuestionTypeUniqueHint',
            self::Multiple => 'surveyQuestionTypeMultipleHint',
            self::Ordre => 'surveyQuestionTypeOrdreHint',
            self::Commentaire => 'surveyQuestionTypeCommentaireHint',
            self::Titre => 'surveyQuestionTypeTitreHint',
        };
    }
}
