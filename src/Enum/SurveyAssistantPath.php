<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The two answers to the survey assistant's first question, « Que voulez-vous faire ? ».
 *
 * The quiz assistant has four (App\Enum\QuizAssistantPath); two of them have no meaning here and
 * their absence is the design rather than an omission:
 *
 *  - **no « depuis une séquence »**: a survey is not about a lesson's content. What it asks about is
 *    a course, a service or a period, and that is a sentence the author writes - there is nothing to
 *    read out of the library that would make the questions truer.
 *  - **no file card**: a survey has no CSV channel, and inventing one to keep the symmetry would be
 *    a second import to maintain for a format nobody produces.
 */
enum SurveyAssistantPath: string
{
    /** I already have the questionnaire written down; put it in the format. */
    case Transpose = 'transpose';

    /** Write it from the subject I state here. */
    case Subject = 'subject';

    public function labelKey(): string
    {
        return match ($this) {
            self::Transpose => 'surveyAssistantPathTransposeTitle',
            self::Subject => 'surveyAssistantPathSubjectTitle',
        };
    }

    /** Whether the model is asked to write questions, as opposed to reformatting the author's own. */
    public function generates(): bool
    {
        return self::Subject === $this;
    }
}
