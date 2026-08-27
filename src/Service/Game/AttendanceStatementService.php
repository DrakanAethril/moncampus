<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\AttendanceStatement;
use App\Entity\AttendanceStatementLine;
use App\Entity\EvaluationPeriod;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AttendanceState;
use App\Enum\GameAttendanceStep;
use App\Repository\AttendanceStatementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Opening a relevé, answering it, and closing it.
 *
 * The one thing worth saying here rather than only in the design: **the statement is opened with
 * every student already net**. That is what makes the pass a minute's work - on thirty students a
 * week costs three or four clicks, not thirty - and it is also why the screen has no « valider » of
 * its own for the ordinary case: doing nothing is a complete, correct statement.
 *
 * Points are re-projected on every edit (App\Service\Game\GameAttendanceProjector), because a
 * statement stays editable until its period closes and a card toggled back to « pas net » has to
 * give its points back.
 */
final class AttendanceStatementService
{
    public function __construct(
        private readonly AttendanceStatementRepository $statements,
        private readonly GameAttendanceProjector $projector,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The statement covering $day, opened if it does not exist yet.
     *
     * The unit is the formation's own step - a week from Monday to Sunday, or a calendar month -
     * and $weeksCovered is what makes the two the same arithmetic downstream.
     */
    public function statementFor(Program $program, EvaluationPeriod $period, \DateTimeImmutable $day, GameAttendanceStep $step, ?User $author = null): AttendanceStatement
    {
        [$start, $end, $weeks] = $this->unitAround($day, $step);

        $statement = $this->statements->findOneCovering($program, $period, $start);

        if (null === $statement) {
            $statement = new AttendanceStatement($program, $period, $start, $end, $weeks, $author);
            $this->entityManager->persist($statement);
        }

        $this->fillMissingLines($statement);
        $this->entityManager->flush();

        // Everybody is net until somebody says otherwise, and « net » pays. Projecting on opening
        // is what makes a statement nobody touches a statement that credited the whole class.
        foreach ($statement->getLines() as $line) {
            $this->projector->project($line->getStudent(), $program, $period);
        }
        $this->entityManager->flush();

        return $statement;
    }

    /**
     * Answer one card. Returns the state actually stored, which is the caller's own answer unless
     * the statement is closed - in which case nothing moves.
     */
    public function setState(AttendanceStatement $statement, User $student, AttendanceState $state): ?AttendanceState
    {
        if ($statement->isClosed()) {
            return null;
        }

        $line = null;
        foreach ($statement->getLines() as $candidate) {
            if ($candidate->getStudent()->getId() === $student->getId()) {
                $line = $candidate;

                break;
            }
        }

        if (null === $line) {
            $line = new AttendanceStatementLine($statement, $student);
            $this->entityManager->persist($line);
        }

        $line->setState($state);
        $this->entityManager->flush();

        $this->projector->project($student, $statement->getProgram(), $statement->getPeriod());
        $this->entityManager->flush();

        return $state;
    }

    /**
     * Close every statement of a period - called by the period closure and by nothing else.
     *
     * A closed statement is no longer editable, which is what stops a term's ranking from moving
     * after the fact.
     */
    public function closePeriod(Program $program, EvaluationPeriod $period, ?\DateTimeImmutable $at = null): int
    {
        $closed = 0;
        foreach ($this->statements->findForPeriod($program, $period) as $statement) {
            if (!$statement->isClosed()) {
                $statement->close($at);
                ++$closed;
            }
        }

        $this->entityManager->flush();

        return $closed;
    }

    /**
     * The counts the screen shows above the grid - « 26 nets · 3 pas nets · 1 hors comptage ».
     *
     * @return array<string, int> keyed by App\Enum\AttendanceState value
     */
    public function tally(AttendanceStatement $statement): array
    {
        $tally = [
            AttendanceState::Clean->value => 0,
            AttendanceState::NotClean->value => 0,
            AttendanceState::OutOfScope->value => 0,
        ];

        foreach ($statement->getLines() as $line) {
            ++$tally[$line->getState()->value];
        }

        return $tally;
    }

    /**
     * A student enrolled after the statement was opened gets their card, net, on the next reading.
     * One who left keeps theirs - what was stated about a week stays stated.
     */
    private function fillMissingLines(AttendanceStatement $statement): void
    {
        $known = [];
        foreach ($statement->getLines() as $line) {
            $known[(int) $line->getStudent()->getId()] = true;
        }

        foreach ($statement->getProgram()->getStudents() as $student) {
            if (isset($known[(int) $student->getId()])) {
                continue;
            }

            $this->entityManager->persist(new AttendanceStatementLine($statement, $student));
        }
    }

    /**
     * The unit of time $day falls in: Monday to Sunday, or the first to the last of the month.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: int}
     */
    public function unitAround(\DateTimeImmutable $day, GameAttendanceStep $step): array
    {
        if (GameAttendanceStep::Month === $step) {
            $start = $day->modify('first day of this month')->setTime(0, 0);
            $end = $day->modify('last day of this month')->setTime(0, 0);

            // How many weeks the month is worth, rounded to the nearest - a 30-day month is four
            // weeks and not 4.28, and the rate normalises whatever this returns anyway.
            $weeks = max(1, (int) round(((int) $start->diff($end)->days + 1) / 7));

            return [$start, $end, $weeks];
        }

        $start = $day->modify('monday this week')->setTime(0, 0);

        return [$start, $start->modify('+6 days'), 1];
    }
}
