<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\EvaluationPeriod;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\GameFamily;
use App\Repository\GameEntryRepository;

/**
 * The live index: the ledger on one side, the possible on the other, and
 * App\Service\Game\GameScoreCalculator between them.
 *
 * Nothing is stored - a balance kept up to date is a balance that drifts, and the ledger is append
 * only precisely so that the answer can always be recomputed. What *is* stored is the snapshot
 * taken at closure (App\Entity\GamePeriodScore), and that one is never recomputed, for the opposite
 * reason: the barème will move, and January's ranking must not move with it.
 *
 * The attendance denominator arrives from outside, because the statement it is counted from does
 * not exist until lot 3 - a formation with no statement simply has no attendance family, its weight
 * spreads over the three others, and the index stays a number out of 100.
 */
final class GameIndexReader
{
    public function __construct(
        private readonly GameEntryRepository $entries,
        private readonly GamePossibleResolver $possible,
        private readonly GameScoreCalculator $calculator,
        private readonly GameWorkReader $work,
        private readonly GameSettingsProvider $settings,
        private readonly GameAttendancePossible $attendance,
    ) {
    }

    public function standingFor(User $student, Program $program, EvaluationPeriod $period, ?\DateTimeImmutable $now = null): GameStanding
    {
        $earned = $this->entries->sumByFamily($student, $program, $period);
        $possible = $this->possibleFor($student, $program, $period, $now);
        $settings = $this->settings->for($program);

        return new GameStanding(
            $student,
            $program,
            $period,
            $this->calculator->compute($earned, $possible, $settings->weights()),
            $earned,
            $possible,
        );
    }

    /**
     * The four denominators of one student.
     *
     * @return array<string, ?int>
     */
    public function possibleFor(User $student, Program $program, EvaluationPeriod $period, ?\DateTimeImmutable $now = null): array
    {
        $settings = $this->settings->for($program);
        $deadlines = array_map(
            static fn (GameWorkDeadline $deadline): int => $deadline->maxPoints,
            $this->work->deadlines($student, $program, $period, $now),
        );

        $attendance = $this->attendance->forStudent($student, $program, $period);

        return [
            GameFamily::Attendance->value => $attendance,
            GameFamily::Work->value => $this->possible->work($deadlines),
            GameFamily::Engagement->value => $this->possible->flat($settings->getEngagementCap()),
            GameFamily::Recognition->value => $this->possible->flat($settings->getRecognitionCap()),
        ];
    }

    /**
     * The same reading for a whole class, in a handful of queries rather than one set per student.
     *
     * @param list<User> $students
     *
     * @return array<int, GameStanding> keyed by student id
     */
    public function standingsFor(array $students, Program $program, EvaluationPeriod $period, ?\DateTimeImmutable $now = null): array
    {
        $earnedAll = $this->entries->sumByFamilyForStudents($students, $program, $period);
        $weights = $this->settings->for($program)->weights();

        $standings = [];
        foreach ($students as $student) {
            $id = (int) $student->getId();
            $earned = $earnedAll[$id] ?? [];
            $possible = $this->possibleFor($student, $program, $period, $now);

            $standings[$id] = new GameStanding(
                $student,
                $program,
                $period,
                $this->calculator->compute($earned, $possible, $weights),
                $earned,
                $possible,
            );
        }

        return $standings;
    }
}
