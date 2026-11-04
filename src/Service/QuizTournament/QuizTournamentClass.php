<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\Program;
use App\Entity\QuizTournament;

/**
 * A tournament seen from one of its classes - the subject of QuizTournamentVoter::OPEN_ROUND when
 * the question is « may this teacher open a round for *this* class », not just « for some class ».
 */
final readonly class QuizTournamentClass
{
    public function __construct(
        public QuizTournament $tournament,
        public Program $program,
    ) {
    }
}
