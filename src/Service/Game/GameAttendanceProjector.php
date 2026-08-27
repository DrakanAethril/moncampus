<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\EvaluationPeriod;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\AttendanceStatementLineRepository;
use App\Repository\GameEntryRepository;

/**
 * Keeps the ledger in step with the relevé, without ever deleting a line.
 *
 * The relevé is the one source that stays **editable** after it has paid: a statement is answered
 * in a minute and corrected five minutes later, and the streak means that changing one week changes
 * what the following ones were worth. So each edit recomputes the whole period for that student and
 * writes, per statement line, the **difference** between what it is worth now and what it has been
 * paid so far.
 *
 * The alternative - reversing and rewriting every line each time - fills the student's journal with
 * pairs that cancel out and say nothing. A signed correction next to the week it belongs to says
 * exactly what happened, once.
 */
final class GameAttendanceProjector
{
    private const string SOURCE = 'AttendanceStatementLine';

    public function __construct(
        private readonly AttendanceStatementLineRepository $lines,
        private readonly GameEntryRepository $entries,
        private readonly GameAttendanceScale $scale,
        private readonly GameRuleResolver $rules,
        private readonly GameSettingsProvider $settings,
        private readonly GameLedger $ledger,
    ) {
    }

    public function project(User $student, Program $program, EvaluationPeriod $period): void
    {
        $lines = $this->lines->findForStudentInPeriod($student, $program, $period);

        if ([] === $lines) {
            return;
        }

        $scale = $this->scaleFor($program, $period, $lines);

        // The unit and its streak are two rules and are written as two lines, each measured against
        // what that rule has already paid on that source. Folding them into one would leave the
        // journal saying « +40 » with nothing to say where the ten came from, and the student would
        // have no way of checking a number they are ranked on.
        $paid = [
            GameRuleCatalog::ATTENDANCE_CLEAN => $this->entries->sumBySourceForRule($student, $program, $period, self::SOURCE, GameRuleCatalog::ATTENDANCE_CLEAN),
            GameRuleCatalog::ATTENDANCE_STREAK => $this->entries->sumBySourceForRule($student, $program, $period, self::SOURCE, GameRuleCatalog::ATTENDANCE_STREAK),
        ];

        foreach ($lines as $offset => $line) {
            $id = (int) $line->getId();
            // Dated on the statement's own week rather than on the click: the journal reads as a
            // calendar, and a correction made in June about March belongs to March.
            $at = $line->getStatement()->getEndsOn()->setTime(12, 0);

            $targets = [
                GameRuleCatalog::ATTENDANCE_CLEAN => $scale[$offset]['points'] - $scale[$offset]['streak'],
                GameRuleCatalog::ATTENDANCE_STREAK => $scale[$offset]['streak'],
            ];

            foreach ($targets as $code => $target) {
                $delta = $target - ($paid[$code][$id] ?? 0);

                if (0 !== $delta) {
                    $this->ledger->adjust($student, $program, $period, $code, $delta, self::SOURCE, $id, $at);
                }
            }
        }
    }

    /** The denominator of the attendance family for one student - the units that concerned them. */
    public function possibleFor(User $student, Program $program, EvaluationPeriod $period): ?int
    {
        $lines = $this->lines->findForStudentInPeriod($student, $program, $period);

        if ([] === $lines) {
            return null;
        }

        $possible = $this->scale->possible($this->scaleFor($program, $period, $lines));

        // Every unit out of scope: the student was concerned by none of them, so the family leaves
        // the index rather than reading 0 %.
        return $possible > 0 ? $possible : null;
    }

    /**
     * @param list<\App\Entity\AttendanceStatementLine> $lines
     *
     * @return list<array{points: int, streak: int, possible: int}>
     */
    private function scaleFor(Program $program, EvaluationPeriod $period, array $lines): array
    {
        $settings = $this->settings->for($program);

        return $this->scale->points(
            array_map(static fn ($line): array => [
                'state' => $line->getState(),
                'weeks' => $line->getStatement()->getWeeksCovered(),
            ], $lines),
            $this->rules->pointsOf($program, $period, GameRuleCatalog::ATTENDANCE_CLEAN),
            $this->rules->pointsOf($program, $period, GameRuleCatalog::ATTENDANCE_STREAK),
            $settings->getAttendanceStreakCap(),
        );
    }
}
