<?php

namespace App\Service;

/**
 * Thrown by QuizLiveSessionService::createSession() when the source QuizTemplate contains a
 * question ineligible for Live mode (QcmMulti/Ordre types, or more than 4 answers on an otherwise
 * eligible question) - the Kahoot-style 4-shape-button UI can't represent either. Carries the
 * offending question labels so the host controller can list them in the rejection message, rather
 * than silently dropping questions from the game.
 */
class LiveTemplateNotEligibleException extends \RuntimeException
{
    /** @param list<string> $offendingQuestionLabels */
    public function __construct(private readonly array $offendingQuestionLabels)
    {
        parent::__construct('Template contains questions ineligible for a live session.');
    }

    /** @return list<string> */
    public function getOffendingQuestionLabels(): array
    {
        return $this->offendingQuestionLabels;
    }
}
