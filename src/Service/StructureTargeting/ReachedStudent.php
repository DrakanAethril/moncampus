<?php

declare(strict_types=1);

namespace App\Service\StructureTargeting;

use App\Entity\Program;
use App\Entity\User;

/**
 * A person a targeting reaches, with « la classe affichée »: their most recent reached Program.
 * Nothing here checks a role - a tournament adds ROLE_STUDENT on top
 * (App\Service\QuizTournament\QuizTournamentEligibility).
 */
final readonly class ReachedStudent
{
    public function __construct(
        public User $user,
        public Program $program,
    ) {
    }
}
