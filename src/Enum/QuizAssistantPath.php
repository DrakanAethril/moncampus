<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The four answers to the assistant's first question, « Que voulez-vous faire ? ».
 *
 * They are the step-1 cards, and they are an enum rather than a pair of booleans because the screen
 * asks *one* question with four answers - "transpose or generate" plus "with a course or not" was
 * two questions the teacher had to answer in the right order, which is exactly what the one-page
 * screen made them do.
 *
 * `Csv` leaves the assistant immediately: the CSV/Kahoot upload is a file, not a conversation, so it
 * has no prompt step and no paste step. It is a card here rather than a tab because it answers the
 * same question as the other three - the pendant of « Je la saisis moi-même » in the séquence
 * assistant (App\Controller\SequenceImportController).
 */
enum QuizAssistantPath: string
{
    /** I already have the questions written down; put them in the format. */
    case Transpose = 'transpose';

    /** Write them from a séquence or a séance of my library. */
    case Course = 'course';

    /** Write them from a subject I state here. */
    case Subject = 'subject';

    /** I have a CSV or a Kahoot export. */
    case Csv = 'csv';

    public function labelKey(): string
    {
        return match ($this) {
            self::Transpose => 'quizAssistantPathTransposeTitle',
            self::Course => 'quizAssistantPathCourseTitle',
            self::Subject => 'quizAssistantPathSubjectTitle',
            self::Csv => 'quizAssistantPathCsvTitle',
        };
    }

    /** Whether the model is asked to write questions, as opposed to reformatting the teacher's own. */
    public function generates(): bool
    {
        return self::Course === $this || self::Subject === $this;
    }

    /** Whether this path walks through the prompt and paste steps at all. */
    public function usesPrompt(): bool
    {
        return self::Csv !== $this;
    }
}
