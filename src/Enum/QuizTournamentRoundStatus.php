<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A round in class (design/validated/quiz-tournament.md §8): open to joiners until the teacher
 * pairs it, or cancels it. It never expires by itself.
 */
enum QuizTournamentRoundStatus: string
{
    case Open = 'open';
    case Paired = 'paired';
    case Cancelled = 'cancelled';
}
