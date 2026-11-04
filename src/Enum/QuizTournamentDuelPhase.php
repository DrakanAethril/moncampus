<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a duel stands at a moment (design/validated/quiz-tournament.md §5.2), as
 * App\Service\QuizTournament\QuizTournamentDuelTimeline reads it from the server's clock: before the
 * first question, a question open, the two seconds after one (right or wrong for each, the score -
 * never the right answer), or over.
 */
enum QuizTournamentDuelPhase: string
{
    case Waiting = 'waiting';
    case Question = 'question';
    case Reveal = 'reveal';
    case Finished = 'finished';
}
