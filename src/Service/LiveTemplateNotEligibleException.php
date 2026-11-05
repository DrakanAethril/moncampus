<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Thrown by QuizLiveSessionService::createSession() when nothing of the chosen quizzes can be
 * played live - every question is of a type the room leaves out (texte à trous, zones, apparier,
 * calculée) or too unfinished to play. A pool with *some* playable questions is never refused: the
 * others are left out and named (QuizLiveSessionService::skippedQuestions()).
 */
class LiveTemplateNotEligibleException extends \RuntimeException
{
    private function __construct()
    {
        parent::__construct('Nothing of this pool can be played in a live session.');
    }

    public static function noPlayableQuestion(): self
    {
        return new self();
    }
}
