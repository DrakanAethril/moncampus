<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Program;
use App\Repository\GameEntryRepository;
use App\Repository\GameProfileRepository;

/**
 * The administration's reading of a class's game - the one screen that answers « is this barème
 * right », which is a question the barème itself cannot answer.
 *
 * Three readings, and each exists because a different mistake is invisible without it:
 *
 * - **Per student**, with real names: the class ranking is anonymous by design and hides its
 *   discreet members, which is right for a student reading their classmates and useless for the
 *   person deciding the thresholds.
 * - **Per rule**, lines *and* points: a rule worth 60 that fires twice and one worth 5 that fires
 *   300 times both weigh 300 points, and only the count says which of the two to argue about. Rules
 *   that paid **nothing** are listed too - a rule nobody ever triggers is the most common mistake in
 *   a barème, and it leaves no trace at all in the ledger.
 * - **Per level**, against the *cursus* total: how many students each threshold has behind it, and
 *   how many months of the observed pace the next one would take. A ladder whose sixth rung takes
 *   eleven years is a ladder nobody climbs, and nothing on the settings screen says so.
 *
 * The pace is extrapolated to 30 days from the part of the window already elapsed, because the
 * useful reading of a barème happens **during** a month, not after it: a median computed on nine
 * days of March would otherwise say the class earns a third of what it does. The median rather than
 * the mean, so one student who declared eight engagements does not move it.
 */
final class GameObservationBoard
{
    /** What a month is worth, for extrapolating the pace - the game's window is the calendar month. */
    private const int MONTH_DAYS = 30;

    public function __construct(
        private readonly GameIndexReader $reader,
        private readonly GameEntryRepository $entries,
        private readonly GameRuleResolver $rules,
        private readonly GameProfileRepository $profiles,
        private readonly GameLevelResolver $levels,
    ) {
    }

    public function for(Program $program, \DateTimeImmutable $from, \DateTimeImmutable $to, ?\DateTimeImmutable $now = null): GameObservation
    {
        $now ??= new \DateTimeImmutable();
        $students = array_values($program->getStudents()->toArray());

        $standings = $this->reader->standingsFor($students, $program, $from, $to, $now);
        $byFamily = $this->entries->sumByFamilyForStudents($students, $program, $from, $to);
        $cursus = $this->entries->sumForStudents($students);
        $profiles = $this->profiles->findForStudents($students);

        $rows = [];
        foreach ($students as $student) {
            $id = (int) $student->getId();
            $cursusPoints = $cursus[$id] ?? 0;

            $rows[] = [
                'student' => $student,
                'index' => $standings[$id]->index(),
                'standing' => $standings[$id],
                'windowPoints' => array_sum($byFamily[$id] ?? []),
                'cursusPoints' => $cursusPoints,
                // Read off the ledger rather than off the stored profile: the profile's level only
                // moves at a closure, and this screen is read in the middle of a month.
                'level' => $this->levels->resolve($cursusPoints)->level->level,
                // `$map[$key]?->method()` does not guard the array access - the profile row only
                // exists once somebody has earned or asked for something.
                'discreet' => ($profiles[$id] ?? null)?->isDiscreet() ?? false,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['index'], $b['windowPoints']] <=> [$a['index'], $a['windowPoints']]);

        $windowPoints = array_sum(array_column($rows, 'windowPoints'));
        $daysSpent = $this->daysSpent($from, $to, $now);
        $pace = $this->pace($rows, $daysSpent);

        return new GameObservation(
            $rows,
            $this->ruleRows($program, $from, $to, $windowPoints),
            $this->levelRows($rows, $pace),
            $windowPoints,
            $pace,
            $daysSpent,
        );
    }

    /**
     * The whole barème with what it produced beside it, heaviest first, then the ones that paid
     * nothing in the catalogue's own order.
     *
     * @return list<array{value: GameRuleValue, lines: int, points: int, share: float, tunable: bool}>
     */
    private function ruleRows(Program $program, \DateTimeImmutable $from, \DateTimeImmutable $to, int $total): array
    {
        $measured = $this->entries->sumByRule($program, $from, $to);
        // A gesture, a council mention and the podium carry the value they were posed with, so their
        // catalogue entry reads 0 - which on a calibration table would say « rule worth nothing »
        // rather than « rule with no fixed value ». The screen needs to tell the two apart.
        $tunable = array_map(static fn (GameRuleDefinition $rule): string => $rule->code, GameRuleCatalog::tunable());
        $rows = [];

        foreach ($this->rules->all($program) as $code => $value) {
            $seen = $measured[$code] ?? ['lines' => 0, 'points' => 0];

            $rows[] = [
                'value' => $value,
                'lines' => $seen['lines'],
                'points' => $seen['points'],
                // Cast: PHP's `/` answers an int when the division is exact, and these shapes
                // promise a float - « 0 » and « 0.0 » are the same share and not the same value.
                'share' => 0 === $total ? 0.0 : (float) $seen['points'] / $total,
                'tunable' => \in_array($code, $tunable, true),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['points'], $b['lines']] <=> [$a['points'], $a['lines']]);

        return $rows;
    }

    /**
     * The six thresholds against the class's cursus totals, and what the observed pace makes of the
     * ones nobody has reached yet.
     *
     * @param list<array{cursusPoints: int, ...}> $rows
     *
     * @return list<array{level: GameLevel, reached: int, share: float, months: float|null}>
     */
    private function levelRows(array $rows, int $pace): array
    {
        $count = \count($rows);
        $levels = [];

        foreach (GameLevels::all() as $level) {
            $reached = \count(array_filter($rows, static fn (array $row): bool => $row['cursusPoints'] >= $level->xpMin));

            $levels[] = [
                'level' => $level,
                'reached' => $reached,
                'share' => 0 === $count ? 0.0 : (float) $reached / $count,
                // Months of the observed pace to get there from zero. Null when nothing is being
                // earned at all - « jamais » is a reading, ∞ is not a number to print.
                'months' => $pace > 0 ? (float) $level->xpMin / $pace : null,
            ];
        }

        return $levels;
    }

    /**
     * The median student's points, brought to a 30-day month.
     *
     * @param list<array{windowPoints: int, ...}> $rows
     */
    private function pace(array $rows, int $daysSpent): int
    {
        if ([] === $rows || $daysSpent <= 0) {
            return 0;
        }

        $points = array_column($rows, 'windowPoints');
        sort($points);
        $middle = intdiv(\count($points), 2);
        $median = 0 === \count($points) % 2
            ? ($points[$middle - 1] + $points[$middle]) / 2
            : $points[$middle];

        return (int) round($median * self::MONTH_DAYS / $daysSpent);
    }

    /** How much of the window is behind us - a running month is read on the days it has had. */
    private function daysSpent(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now): int
    {
        $end = min($to, $now);

        if ($end < $from) {
            return 0;
        }

        return max(1, $from->diff($end)->days ?? 1);
    }
}
