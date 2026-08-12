<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizQuestion;
use App\Enum\QuestionType;
use App\Enum\QuizQuestionGap;
use App\Enum\ZoneSupportKind;

/**
 * Whether a question can be played, and if not, why - the single rule behind the "incomplète" state
 * of the import preview, the warning banner of the question bank and the lock on the launch screen.
 *
 * It reads a library question rather than a QuizQuestionDefinition on purpose: an instance question
 * is a snapshot of something that was already allowed to launch, so the question never arises there.
 */
final class QuizQuestionCompleteness
{
    public function gapOf(QuizQuestion $question): ?QuizQuestionGap
    {
        // Zones first: a légende drawn on an image whose zones are unplaced also has no image, and
        // "ouvrir l'éditeur" is the answer there, not "joindre un fichier".
        if ($question->getType()->usesZoneConfig()) {
            return ZoneSupportKind::Image === $question->getZoneKind() && null === $question->getImageStorageKey()
                ? QuizQuestionGap::Zones
                : null;
        }

        if (null !== $question->getExpectedMediaName()) {
            return QuizQuestionGap::Media;
        }

        // An image question with no image is the same broken passation as a named-but-missing one:
        // the student is asked about a picture nobody can see.
        return QuestionType::Image === $question->getType() && null === $question->getImageStorageKey()
            ? QuizQuestionGap::Media
            : null;
    }

    /**
     * @param iterable<QuizQuestion> $questions
     *
     * @return list<QuizQuestion>
     */
    public function incomplete(iterable $questions): array
    {
        $incomplete = [];
        foreach ($questions as $question) {
            if (null !== $this->gapOf($question)) {
                $incomplete[] = $question;
            }
        }

        return $incomplete;
    }

    /** @param iterable<QuizQuestion> $questions */
    public function countIncomplete(iterable $questions): int
    {
        return \count($this->incomplete($questions));
    }
}
