<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Thrown by QuizLiveSessionService::createSession() when the source QuizTemplate cannot be played
 * live. Two distinct reasons, which the host controller phrases differently:
 * - it contains a question ineligible for Live mode (QcmMulti/Ordre types, or more than 4 answers
 *   on an otherwise eligible question) - the Kahoot-style 4-shape-button UI can't represent either.
 *   The offending labels are carried so the rejection message can list them, rather than silently
 *   dropping questions from the game.
 * - nothing is left to play at all once the texte à trous questions are skipped (those are dropped
 *   on purpose, see QuizLiveSessionService::liveQuestions()) - there are no labels to list, the
 *   whole template is simply unplayable live.
 */
class LiveTemplateNotEligibleException extends \RuntimeException
{
    /** @param list<string> $offendingQuestionLabels */
    private function __construct(private readonly array $offendingQuestionLabels, private readonly bool $hasPlayableQuestions)
    {
        parent::__construct('Template contains questions ineligible for a live session.');
    }

    /** @param list<string> $offendingQuestionLabels */
    public static function ineligibleQuestions(array $offendingQuestionLabels): self
    {
        return new self($offendingQuestionLabels, true);
    }

    public static function noPlayableQuestion(): self
    {
        return new self([], false);
    }

    /** @return list<string> */
    public function getOffendingQuestionLabels(): array
    {
        return $this->offendingQuestionLabels;
    }

    public function hasPlayableQuestions(): bool
    {
        return $this->hasPlayableQuestions;
    }
}
