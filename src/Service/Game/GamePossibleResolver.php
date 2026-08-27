<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Enum\GameFamily;

/**
 * What it was *possible* to earn, family by family, for one student on one period
 * (design/validated/gamification.md §2).
 *
 * The denominator is the half of the index nobody can check by looking at a screen, and it is the
 * half that decides whether an apprentice is ranked on their behaviour or on their availability.
 * Four rules, and each of them says why:
 *
 * - **Attendance**: the units that concerned this student. An out-of-scope unit - work placement,
 *   sick leave, an arrival in November - is not passed in at all, so it lowers the possible instead
 *   of costing points. A week nobody stated exists for nobody.
 * - **Work**: this student's own deadlines, each contributing what it could have paid. Four
 *   deadlines honoured out of four are worth exactly as much as twelve out of twelve.
 * - **Engagement** and **recognition**: a flat cap, identical for everybody. Volunteering has no
 *   denominator - the occasion is the same, only the decision differs - and every student sits the
 *   same council.
 *
 * Primitives in, primitives out: this is the other class the design asks to be tested before it is
 * written (tests/Service/Game/GamePossibleResolverTest.php).
 */
final class GamePossibleResolver
{
    /**
     * @param int $countedUnits units of the attendance statement that concerned this student, the out-of-scope ones already removed
     * @param int $pointsPerUnit what one net unit pays
     *
     * @return int|null null when nothing was stated - the family leaves the index
     */
    public function attendance(int $countedUnits, int $pointsPerUnit): ?int
    {
        return $countedUnits > 0 && $pointsPerUnit > 0 ? $countedUnits * $pointsPerUnit : null;
    }

    /**
     * @param list<int> $deadlineMaxima what each deadline of the period could have paid, in order of nothing in particular
     *
     * @return int|null null when the student had no deadline at all on the period
     */
    public function work(array $deadlineMaxima): ?int
    {
        $total = array_sum($deadlineMaxima);

        return $total > 0 ? $total : null;
    }

    /** A flat cap; zero is the only way to take one of the two forfait families out of the index. */
    public function flat(int $cap): ?int
    {
        return $cap > 0 ? $cap : null;
    }

    /**
     * @param list<int> $deadlineMaxima
     *
     * @return array<string, ?int>
     */
    public function resolve(int $countedUnits, int $pointsPerUnit, array $deadlineMaxima, int $engagementCap, int $recognitionCap): array
    {
        return [
            GameFamily::Attendance->value => $this->attendance($countedUnits, $pointsPerUnit),
            GameFamily::Work->value => $this->work($deadlineMaxima),
            GameFamily::Engagement->value => $this->flat($engagementCap),
            GameFamily::Recognition->value => $this->flat($recognitionCap),
        ];
    }
}
