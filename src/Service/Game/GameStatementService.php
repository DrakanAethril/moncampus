<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\GameStatement;
use App\Entity\GameStatementLine;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AttendanceState;
use App\Enum\CouncilMention;
use App\Enum\GameAttendanceStep;
use App\Enum\GameStatementType;
use App\Repository\GameEntryRepository;
use App\Repository\GameStatementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creating a relevé, answering it, and closing it - for both kinds.
 *
 * **A relevé is created by hand and named by hand**, and there may be as many or as few as a team
 * wants: three councils in a year or one, a weekly attendance pass or a monthly one. The school's
 * evaluation periods still carry the index, but they no longer dictate how many documents get
 * filled in - which is what they did until 2026-08-27, and what made a second council impossible.
 *
 * The two kinds differ in when their points land. An **attendance** relevé pays as it is answered,
 * because it stays editable and a card toggled back has to give its points back
 * (App\Service\Game\GameAttendanceProjector). A **council** pays nothing until it is closed, and
 * then all at once: crediting as the professeur principal types would mean a ranking moving during
 * a deliberation, which is the one thing screen 6 exists to prevent.
 */
final class GameStatementService
{
    private const string SOURCE = 'GameStatementLine';

    public function __construct(
        private readonly GameStatementRepository $statements,
        private readonly GameEntryRepository $entries,
        private readonly GameAttendanceProjector $projector,
        private readonly GamePeriodResolver $periods,
        private readonly GameLedger $ledger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** A council relevé: a label and a day, and nothing else to fill in. */
    public function createCouncil(Program $program, string $name, \DateTimeImmutable $heldOn, ?User $author = null): GameStatement
    {
        $statement = new GameStatement($program, GameStatementType::Council, $name, $heldOn, $author);
        $this->entityManager->persist($statement);

        $this->fillMissingLines($statement);
        $this->entityManager->flush();

        return $statement;
    }

    /**
     * An attendance relevé, covering one unit of time.
     *
     * Refuses to open a second one over the same span - two passes over one week would each pay,
     * and the denominator would count the week twice.
     */
    public function createAttendance(Program $program, string $name, GameAttendanceStep $periodicity, \DateTimeImmutable $day, ?User $author = null): GameStatement
    {
        [$start, $end, $weeks] = $this->unitAround($day, $periodicity);

        $existing = $this->statements->findAttendanceStartingOn($program, $start);

        if (null !== $existing) {
            return $existing;
        }

        $statement = new GameStatement($program, GameStatementType::Attendance, $name, $end, $author);
        $statement->coverTimeSpan($periodicity, $start, $end, $weeks);
        $this->entityManager->persist($statement);

        $this->fillMissingLines($statement);
        $this->entityManager->flush();

        $this->projectAll($statement);

        return $statement;
    }

    /** Answer one attendance card. Null when the relevé is closed and nothing moved. */
    public function setState(GameStatement $statement, User $student, AttendanceState $state, ?User $by = null): ?AttendanceState
    {
        if ($statement->isClosed() || GameStatementType::Attendance !== $statement->getType()) {
            return null;
        }

        $this->lineFor($statement, $student)->setState($state, $by);
        $this->entityManager->flush();

        $this->projector->project($student, $statement->getProgram());
        $this->entityManager->flush();

        return $state;
    }

    /** Place one council mention. Null when the council is closed and the mentions are locked. */
    public function setMention(GameStatement $statement, User $student, CouncilMention $mention, ?User $by = null): ?CouncilMention
    {
        if ($statement->isClosed() || GameStatementType::Council !== $statement->getType()) {
            return null;
        }

        $this->lineFor($statement, $student)->setMention($mention, $by);
        $this->entityManager->flush();

        return $mention;
    }

    public function setComment(GameStatement $statement, User $student, ?string $comment, ?User $by = null): bool
    {
        if ($statement->isClosed()) {
            return false;
        }

        $this->lineFor($statement, $student)->setComment($comment, $by);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Close a relevé.
     *
     * A council inserts its points here, in one gesture, into the period its own day falls in. An
     * attendance relevé has already paid as it went, so closing it only stops it being edited.
     *
     * Idempotent through the ledger's own refusal on (sourceType, sourceId, ruleCode).
     */
    public function close(GameStatement $statement, ?\DateTimeImmutable $at = null): int
    {
        if ($statement->isClosed()) {
            return 0;
        }

        $at ??= new \DateTimeImmutable();
        $statement->close($at);
        $credited = 0;

        if (GameStatementType::Council === $statement->getType()) {
            // The period the relevé's own day falls in, and **no fallback**: a council held outside
            // every evaluation period closes and keeps its mentions - they are a record of what the
            // council said, true whether or not a game is being played - but it credits nothing,
            // there being no index for the points to count towards. The screen says exactly that,
            // and falling back on the active period would make it a lie.
            $period = $this->periods->periodContaining($statement->getProgram(), $statement->getHeldOn());

            if (null !== $period) {
                foreach ($statement->getLines() as $line) {
                    $points = $line->councilPoints();

                    if ($points <= 0) {
                        // « Aucune » and « avertissement » are both worth zero, and a zero-point line
                        // in a student's journal reads as a bug rather than as a decision.
                        continue;
                    }

                    $entry = $this->ledger->record(
                        $line->getStudent(),
                        $statement->getProgram(),
                        $period,
                        GameRuleCatalog::RECOGNITION_COUNCIL,
                        self::SOURCE,
                        (int) $line->getId(),
                        $at,
                        $points,
                    );

                    if (null !== $entry) {
                        ++$credited;
                    }
                }
            }
        }

        $this->entityManager->flush();

        return $credited;
    }

    /**
     * Re-open a closed council - an administrator's act.
     *
     * The points already inserted are undone by inverse lines and re-inserted by the next close(),
     * never by a recount: a student who saw their hundred points arrive has to be able to see them
     * leave (§9).
     */
    public function reopen(GameStatement $statement, User $author): void
    {
        if (!$statement->isClosed()) {
            return;
        }

        $statement->reopen();

        foreach ($statement->getLines() as $line) {
            foreach ($this->entries->findBySource($line->getStudent(), self::SOURCE, (int) $line->getId(), GameRuleCatalog::RECOGNITION_COUNCIL) as $entry) {
                if (null === $this->entries->findReversalOf($entry) && !$entry->isReversal()) {
                    $this->ledger->reverse($entry, $author, null);
                }
            }
        }

        $this->entityManager->flush();
    }

    /** Close every attendance relevé of a formation - what the period closure calls. */
    public function closeAttendanceUpTo(Program $program, \DateTimeImmutable $upTo): int
    {
        $closed = 0;

        foreach ($this->statements->attendanceInOrder($program) as $statement) {
            if ($statement->isClosed() || ($statement->getEndsOn() ?? $statement->getHeldOn()) > $upTo) {
                continue;
            }

            $statement->close($upTo);
            ++$closed;
        }

        $this->entityManager->flush();

        return $closed;
    }

    /**
     * The counts above an attendance grid - « 26 nets · 3 pas nets · 1 hors comptage ».
     *
     * @return array<string, int> keyed by App\Enum\AttendanceState value
     */
    public function tally(GameStatement $statement): array
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

    /** Students enrolled after a relevé was opened get their line, net, on the next reading. */
    public function refreshLines(GameStatement $statement): void
    {
        $this->fillMissingLines($statement);
        $this->entityManager->flush();

        if (GameStatementType::Attendance === $statement->getType()) {
            $this->projectAll($statement);
        }
    }

    /**
     * The unit of time a day falls in: Monday to Sunday, or the first to the last of the month.
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

    private function lineFor(GameStatement $statement, User $student): GameStatementLine
    {
        foreach ($statement->getLines() as $line) {
            if ($line->getStudent()->getId() === $student->getId()) {
                return $line;
            }
        }

        $line = new GameStatementLine($statement, $student);
        $this->entityManager->persist($line);

        return $line;
    }

    private function fillMissingLines(GameStatement $statement): void
    {
        $known = [];
        foreach ($statement->getLines() as $line) {
            $known[(int) $line->getStudent()->getId()] = true;
        }

        foreach ($statement->getProgram()->getStudents() as $student) {
            if (!isset($known[(int) $student->getId()])) {
                $this->entityManager->persist(new GameStatementLine($statement, $student));
            }
        }
    }

    /**
     * Everybody is net until somebody says otherwise, and « net » pays. Projecting on opening is
     * what makes a relevé nobody touches a relevé that credited the whole class.
     */
    private function projectAll(GameStatement $statement): void
    {
        foreach ($statement->getLines() as $line) {
            $this->projector->project($line->getStudent(), $statement->getProgram());
        }

        $this->entityManager->flush();
    }
}
