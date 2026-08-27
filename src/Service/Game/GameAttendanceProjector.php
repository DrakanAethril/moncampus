<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\EvaluationPeriod;
use App\Entity\GameStatementLine;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\GameEntryRepository;
use App\Repository\GameStatementLineRepository;

/**
 * Keeps the ledger in step with the attendance relevés, without ever deleting a line.
 *
 * The relevé is the one source that stays **editable** after it has paid: it is answered in a minute
 * and corrected five minutes later, and the streak means that changing one week changes what the
 * following ones were worth. So each edit recomputes the whole formation for that student and
 * writes, per line, the **difference** between what it is worth now and what it has been paid.
 *
 * The alternative - reversing and rewriting every line each time - fills the student's journal with
 * pairs that cancel out and say nothing. A signed correction next to the week it belongs to says
 * exactly what happened, once.
 *
 * Two things follow from relevés no longer belonging to a period (2026-08-27):
 *
 * - **The scale is computed across the whole formation, in date order**, and a streak therefore runs
 *   through a term boundary. Nothing says it should stop there, and a run of clean weeks broken
 *   only by the calendar would be a rule nobody could explain to a student.
 * - **Nothing is filed into a period.** A line pays on the day its relevé covers, and which period
 *   that day belongs to is read afterwards; a relevé held outside every period is still paid and
 *   still in the journal, it simply counts towards no index. The screen says so.
 */
final class GameAttendanceProjector
{
    private const string SOURCE = 'GameStatementLine';

    public function __construct(
        private readonly GameStatementLineRepository $lines,
        private readonly GameEntryRepository $entries,
        private readonly GameAttendanceScale $scale,
        private readonly GameRuleResolver $rules,
        private readonly GameSettingsProvider $settings,
        private readonly GamePeriodResolver $periods,
        private readonly GameLedger $ledger,
    ) {
    }

    public function project(User $student, Program $program): void
    {
        $lines = $this->lines->attendanceForStudent($student, $program);

        if ([] === $lines) {
            return;
        }

        $scale = $this->scaleFor($program, $lines);

        foreach ($lines as $offset => $line) {
            $paid = [
                GameRuleCatalog::ATTENDANCE_CLEAN => $this->entries->sumBySourceForRule($student, $program, self::SOURCE, GameRuleCatalog::ATTENDANCE_CLEAN),
                GameRuleCatalog::ATTENDANCE_STREAK => $this->entries->sumBySourceForRule($student, $program, self::SOURCE, GameRuleCatalog::ATTENDANCE_STREAK),
            ];

            $id = (int) $line->getId();
            // Dated on the relevé's own span rather than on the click: the journal reads as a
            // calendar, and a correction made in June about March belongs to March.
            $at = ($line->getStatement()->getEndsOn() ?? $line->getStatement()->getHeldOn())->setTime(12, 0);

            $targets = [
                GameRuleCatalog::ATTENDANCE_CLEAN => $scale[$offset]['points'] - $scale[$offset]['streak'],
                GameRuleCatalog::ATTENDANCE_STREAK => $scale[$offset]['streak'],
            ];

            foreach ($targets as $code => $target) {
                $delta = $target - ($paid[$code][$id] ?? 0);

                if (0 !== $delta) {
                    $this->ledger->adjust($student, $program, $code, $delta, self::SOURCE, $id, $at);
                }
            }
        }
    }

    /**
     * The denominator of the attendance family for one student **on one period**: the units that
     * concerned them and whose relevé falls inside it.
     *
     * The scale is still computed over the whole formation, because that is where the streak lives;
     * only the summing is per period.
     */
    public function possibleFor(User $student, Program $program, EvaluationPeriod $period): ?int
    {
        $lines = $this->lines->attendanceForStudent($student, $program);

        if ([] === $lines) {
            return null;
        }

        $scale = $this->scaleFor($program, $lines);
        $possible = 0;
        $counted = false;

        foreach ($lines as $offset => $line) {
            if ($this->periodOf($program, $line)?->getId() !== $period->getId()) {
                continue;
            }

            $counted = true;
            $possible += $scale[$offset]['possible'];
        }

        // Nothing stated on this period, or every unit out of scope: the family leaves the index
        // rather than reading 0 %.
        return $counted && $possible > 0 ? $possible : null;
    }

    private function periodOf(Program $program, GameStatementLine $line): ?EvaluationPeriod
    {
        $statement = $line->getStatement();

        return $this->periods->periodContaining($program, ($statement->getEndsOn() ?? $statement->getHeldOn())->setTime(12, 0));
    }

    /**
     * @param list<GameStatementLine> $lines
     *
     * @return list<array{points: int, streak: int, possible: int}>
     */
    private function scaleFor(Program $program, array $lines): array
    {
        $settings = $this->settings->for($program);

        $unit = $this->rules->pointsOf($program, GameRuleCatalog::ATTENDANCE_CLEAN);
        $streak = $this->rules->pointsOf($program, GameRuleCatalog::ATTENDANCE_STREAK);

        return $this->scale->points(
            array_map(static fn (GameStatementLine $line): array => [
                'state' => $line->getState(),
                'weeks' => $line->getStatement()->getWeeksCovered(),
            ], $lines),
            $unit,
            $streak,
            $settings->getAttendanceStreakCap(),
        );
    }
}
