<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Program;
use App\Entity\User;

/**
 * How many attendance points one student could have earned on a period.
 *
 * A thin seam over App\Service\Game\GameAttendanceProjector, kept because it is what
 * App\Service\Game\GameIndexReader asks and because the answer has one property the rest of the
 * index does not: **null is normal**. A formation where nobody makes a statement has no attendance
 * family, its weight goes to the other three, and no screen says anything about it (§9, first row).
 */
class GameAttendancePossible
{
    public function __construct(private readonly GameAttendanceProjector $projector)
    {
    }

    /** @return int|null null when nothing was stated for this student - the family leaves the index */
    public function forStudent(User $student, Program $program, \DateTimeImmutable $from, \DateTimeImmutable $to): ?int
    {
        return $this->projector->possibleFor($student, $program, $from, $to);
    }
}
