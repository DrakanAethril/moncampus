<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\EvaluationPeriod;
use App\Entity\Program;
use App\Entity\User;

/**
 * How many attendance points one student could have earned on a period.
 *
 * A seam rather than a service: until the relevé exists (lot 3) the answer is « nothing was
 * stated », which is not a failure - the attendance family simply leaves the index and its weight
 * spreads over the other three (§9, first row). Lot 3 replaces the body and nothing else moves,
 * which is what makes lot 1 usable on its own.
 */
class GameAttendancePossible
{
    /** @return int|null null when nothing was stated for this student - the family leaves the index */
    public function forStudent(User $student, Program $program, EvaluationPeriod $period): ?int
    {
        return null;
    }
}
