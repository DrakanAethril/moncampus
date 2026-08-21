<?php

declare(strict_types=1);

namespace App\Service\Survey;

use App\Entity\SurveyAnswerDefinition;
use App\Entity\SurveyCampaign;
use App\Entity\SurveyQuestionDefinition;

/**
 * One survey question, as the mobile app reads it - the five types described in **one** object,
 * with null fields where a type does not apply, rather than five shapes for the client to tell
 * apart (design/validated/surveys.md §10.2).
 *
 * The same choice App\Service\QuizQuestionPayload makes for the twelve quiz types, and for the same
 * reason: one contract that cannot drift instead of five that can.
 *
 * Two things are deliberately absent, because a survey has no answers to hide and no score to
 * withhold: there is nothing here about correctness, and nothing about grading. What *is* absent
 * for another reason is the Titre line - it never appears in the payload's `answers` on the way
 * back, and questionCount excludes it, or the app's progress bar would never reach its maximum.
 */
class SurveyQuestionPayload
{
    /**
     * @return array<string, mixed>
     */
    public function build(SurveyQuestionDefinition $question, int $id): array
    {
        $type = $question->getType();

        $answers = [];
        foreach ($question->getAnswers() as $answer) {
            /* @var SurveyAnswerDefinition $answer */
            $answers[] = [
                'id' => $answer->getId(),
                'label' => $answer->getLabel(),
                'orderIndex' => $answer->getOrderIndex(),
            ];
        }

        return [
            'id' => $id,
            'type' => $type->value,
            'label' => $question->getLabel(),
            'orderIndex' => $question->getOrderIndex(),
            'helpText' => $question->getHelpText(),
            'required' => $question->isRequired(),
            // Only meaningful on a single choice - it changes nothing for the respondent, only the
            // reading of the results, but the app carries it so a future scale rendering needs no
            // new field.
            'isScale' => $type->supportsScale() && $question->isScale(),
            'minChoices' => $type->supportsChoiceBounds() ? $question->getMinChoices() : null,
            'maxChoices' => $type->supportsChoiceBounds() ? $question->getMaxChoices() : null,
            // The comment's cap, sent rather than hardcoded in the app: the counter it shows has to
            // be the server's own limit, or a silent truncation is exactly what happens.
            'maxLength' => $type->hasAnswers() ? null : \App\Entity\SurveyResponseAnswer::FREE_TEXT_MAX_LENGTH,
            'answers' => $answers,
        ];
    }

    /**
     * A whole campaign - what GET /api/surveys/{id} answers.
     *
     * @return array<string, mixed>
     */
    public function campaign(SurveyCampaign $campaign): array
    {
        $questions = [];
        foreach ($campaign->getQuestions() as $question) {
            $questions[] = $this->build($question, (int) $question->getId());
        }

        return [
            'id' => $campaign->getId(),
            'name' => $campaign->getName(),
            'description' => $campaign->getDescription(),
            // Drives the notice the app shows before the first question. It is a promise made to
            // the respondent, so it cannot exist on one support and not the other.
            'anonymous' => $campaign->isAnonymous(),
            'closesAt' => $campaign->getClosesAt()?->format(\DateTimeInterface::ATOM),
            // Titre lines excluded - see §7.13.
            'questionCount' => $campaign->answerableQuestionCount(),
            'questions' => $questions,
        ];
    }
}
