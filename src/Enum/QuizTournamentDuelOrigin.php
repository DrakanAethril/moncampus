<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where a duel came from: an accepted challenge, or a round a teacher paired in class
 * (design/validated/quiz-tournament.md §8). Both are ordinary duels - same rating, same activity,
 * same daily cap.
 */
enum QuizTournamentDuelOrigin: string
{
    case Challenge = 'challenge';
    case Round = 'round';
}
