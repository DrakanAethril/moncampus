<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\GroupBatch;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\QuizTournament;
use App\Entity\QuizTournamentDuel;
use App\Entity\QuizTournamentRound;
use App\Entity\User;
use App\Enum\QuizTournamentDuelOrigin;
use App\Repository\GroupBatchRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\QuizTournamentDuelRepository;
use App\Repository\QuizTournamentPlayerRepository;
use App\Repository\QuizTournamentRoundRepository;
use App\Service\StructureTargeting\StructureTargeting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The rounds in class (design/validated/quiz-tournament.md §8): opening one, who it expects,
 * joining and leaving it, « Clore et apparier », « Annuler la ronde », and what a student's
 * tournament screens say of it. Every gesture reads its facts again here, server-side, and refuses
 * with the sentence the screen shows (QuizTournamentRefusal); the decisions are
 * QuizTournamentRoundRules' and QuizTournamentRoundPairing's.
 *
 * - **Open**: one round per class at a time; the restriction an Option of the class or a group of
 *   a lot of that class the teacher saved or was shared (GroupBatchRepository's « readable » lots).
 *   The students it expects are told on their tournament screens.
 * - **Expected**: the class's students still eligible, narrowed to the restriction - a group is
 *   read **on the day**, its members still enrolled and eligible; a lot deleted since leaves
 *   nobody to expect.
 * - **Join / leave / beat**: an expected student who may play joins from a tournament screen, which
 *   then beats every QuizTournamentRoundRules::BEAT_SECONDS (QuizTournamentPlayer::$lastSeenAt);
 *   a beat older than PRESENCE_TIMEOUT_SECONDS means they left the page.
 * - **Close and pair**: the entrants - participants still expected, present, able to play, not in
 *   a duel anywhere - paired by rating; every duel made by QuizTournamentDuelFactory with the
 *   **same** start, origin Round; the odd one out exempted (QuizTournamentPlayer::recordRoundExemption()).
 *   Then each player's screen is sent to their duel over Mercure. Round duels are ordinary duels:
 *   rating, placement, activity, ladder, daily cap.
 * - **Cancel**: a round not paired is cancelled; it never expires by itself.
 *
 * Joining, closing and cancelling run under one lock per round (LOCK_DSN), the round row read FOR
 * UPDATE when it is paired: a join that arrives during the pairing waits for it, then finds the
 * round closed.
 */
class QuizTournamentRounds
{
    private const int LOCK_TTL_SECONDS = 30;

    /** How long after its pairing a round still tells its students where they stand. */
    public const int ENTRY_AFTER_PAIRING_MINUTES = 60;

    public function __construct(
        private readonly QuizTournamentAccess $access,
        private readonly StructureTargeting $targeting,
        private readonly QuizTournamentRoundRepository $rounds,
        private readonly QuizTournamentPlayerRepository $players,
        private readonly QuizTournamentDuelRepository $duels,
        private readonly ProgramStudentOptionRepository $studentOptions,
        private readonly GroupBatchRepository $groupBatches,
        private readonly QuizTournamentDuelFactory $factory,
        private readonly QuizTournamentStandingsAssembler $standings,
        private readonly QuizTournamentLive $live,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * The lots of this class a teacher may narrow a round to: their own, and those shared with them.
     *
     * @return list<GroupBatch>
     */
    public function readableBatches(User $teacher, Program $program): array
    {
        return $this->groupBatches->findAllReadableForTeacherAndPrograms($teacher, [$program]);
    }

    /**
     * Opens a round for a class. $canOpen is QuizTournamentVoter::OPEN_ROUND for that class.
     *
     * @throws QuizTournamentRefusal
     */
    public function open(QuizTournament $tournament, Program $program, User $teacher, bool $canOpen, ?Option $option, ?GroupBatch $batch, ?int $groupIndex, \DateTimeImmutable $now): QuizTournamentRound
    {
        $lock = $this->lockFactory->createLock(\sprintf('quiz-tournament-rounds-%d-%d', (int) $tournament->getId(), (int) $program->getId()), self::LOCK_TTL_SECONDS);
        $lock->acquire(true);
        try {
            $refusal = QuizTournamentRoundRules::openRefusal(new QuizTournamentRoundOpening(
                (int) $program->getId(),
                $canOpen,
                null !== $this->rounds->findOpenForProgram($tournament, $program),
                $option?->getId(),
                array_values(array_map(static fn (Option $o): int => (int) $o->getId(), $program->getOptions()->toArray())),
                $batch?->getId(),
                $batch?->getProgram()->getId(),
                null !== $batch && ($batch->getTeacher() === $teacher || $batch->isSharedWith($teacher)),
                $groupIndex,
                null === $batch ? 0 : \count($batch->getGroups()),
            ));
            if (null !== $refusal) {
                throw new QuizTournamentRefusal($refusal->labelKey());
            }

            $round = new QuizTournamentRound($tournament, $program, $teacher, $now);
            if (null !== $option) {
                $round->restrictToOption($option);
            } elseif (null !== $batch && null !== $groupIndex) {
                $round->restrictToGroup($batch, $groupIndex);
            }
            $this->entityManager->persist($round);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        $this->live->publishRoundChanged($tournament, ...$this->expectedStudents($round));

        return $round;
    }

    /**
     * The students the round expects, in the class's order (surname, first name) - read now.
     *
     * @return list<User>
     */
    public function expectedStudents(QuizTournamentRound $round): array
    {
        $students = $this->classStudents($round->getProgram());
        $ids = QuizTournamentRoundRules::expected(
            array_keys($students),
            array_keys($this->targeting->reachedStudents($round->getTournament())),
            $this->restrictionIds($round),
        );

        return array_map(static fn (int $id): User => $students[$id], $ids);
    }

    /** Is this student one the round expects - the per-person reading of expectedStudents()? */
    public function isExpected(QuizTournamentRound $round, User $student): bool
    {
        $id = (int) $student->getId();
        $enrolled = \in_array('ROLE_STUDENT', $student->getRoles(), true) && $round->getProgram()->getStudents()->contains($student);

        return [] !== QuizTournamentRoundRules::expected(
            $enrolled ? [$id] : [],
            $enrolled && $this->access->isEligible($round->getTournament(), $student) ? [$id] : [],
            $this->restrictionIds($round, $student),
        );
    }

    /** @throws QuizTournamentRefusal */
    public function join(QuizTournamentRound $round, User $student, \DateTimeImmutable $now): void
    {
        $this->locked($round, function () use ($round, $student, $now): void {
            $this->entityManager->refresh($round);
            $refusal = QuizTournamentRoundRules::joinRefusal(
                $round->isOpen(),
                $this->access->canPlay($round->getTournament(), $student, $now),
                $this->isExpected($round, $student),
            );
            if (null !== $refusal) {
                throw new QuizTournamentRefusal($refusal->labelKey());
            }

            $this->players->findOrCreate($round->getTournament(), $student)->markSeen($now);
            $round->join($student);
            $this->entityManager->flush();
        });

        $this->live->publishRoster($round);
    }

    public function leave(QuizTournamentRound $round, User $student): void
    {
        $left = $this->locked($round, function () use ($round, $student): bool {
            $this->entityManager->refresh($round);
            if (!$round->isOpen() || !$round->getParticipants()->contains($student)) {
                return false;
            }
            $round->leave($student);
            $this->entityManager->flush();

            return true;
        });

        if ($left) {
            $this->live->publishRoster($round);
        }
    }

    /**
     * The page of a student who joined says they are still there. Answers where they stand - what
     * the page does next (QuizTournamentRounds::entryState()).
     *
     * @return array{state: string, duelUrl: string|null}
     */
    public function beat(QuizTournamentRound $round, User $student, \DateTimeImmutable $now): array
    {
        if ($round->isOpen() && $round->getParticipants()->contains($student)) {
            $player = $this->players->findOrCreate($round->getTournament(), $student);
            $wasPresent = QuizTournamentRoundRules::isPresent($player->getLastSeenAt(), $now);
            $player->markSeen($now);
            $this->entityManager->flush();
            if (!$wasPresent) {
                $this->live->publishRoster($round);
            }
        }

        return $this->entryState($round, $student, $now);
    }

    /**
     * « Clore et apparier ». $canOpen is QuizTournamentVoter::OPEN_ROUND for the round's class, read
     * now: the tournament still running, the teacher still teaching it.
     *
     * @throws QuizTournamentRefusal
     */
    public function closeAndPair(QuizTournamentRound $round, bool $canOpen, \DateTimeImmutable $now): void
    {
        $now = QuizTournamentDuelClock::second($now);
        $tournament = $round->getTournament();

        /** @var array{0: list<QuizTournamentDuel>, 1: list<User>} $outcome */
        $outcome = $this->locked($round, function () use ($round, $canOpen, $now, $tournament): array {
            // Decided before the transaction: a refusal must not close the entity manager.
            $this->entityManager->refresh($round);

            $participants = [];
            foreach ($round->getParticipants() as $participant) {
                $participants[(int) $participant->getId()] = $participant;
            }
            $participantIds = array_keys($participants);
            $lastSeen = $this->players->lastSeenOf($tournament, $participantIds);

            $entrantIds = QuizTournamentRoundRules::entrants(
                $participantIds,
                array_map(static fn (User $user): int => (int) $user->getId(), $this->expectedStudents($round)),
                $lastSeen,
                array_values(array_filter($participantIds, fn (int $id): bool => $this->access->canPlay($tournament, $participants[$id], $now))),
                $this->duels->busyAmong($participantIds),
                $now,
            );

            $refusal = QuizTournamentRoundRules::closeRefusal($round->isOpen(), $canOpen, \count($entrantIds));
            if (null !== $refusal) {
                throw new QuizTournamentRefusal($refusal->labelKey());
            }

            /** @var list<QuizTournamentDuel> $duels */
            $duels = $this->entityManager->wrapInTransaction(fn (): array => $this->pair($round, $participants, $entrantIds, $now));
            $paired = [];
            foreach ($duels as $duel) {
                $paired[] = (int) $duel->getPlayerA()->getId();
                $paired[] = (int) $duel->getPlayerB()->getId();
            }
            $others = array_values(array_filter($participants, static fn (User $user): bool => !\in_array((int) $user->getId(), $paired, true)));

            return [$duels, $others];
        });

        [$duels, $others] = $outcome;
        foreach ($duels as $duel) {
            $this->live->publishRoundDuel($duel, $duel->getPlayerA());
            $this->live->publishRoundDuel($duel, $duel->getPlayerB());
        }
        $this->live->publishRoundChanged($tournament, ...$others);
        $this->live->publishRoster($round);
    }

    public function cancel(QuizTournamentRound $round, \DateTimeImmutable $now): void
    {
        $cancelled = $this->locked($round, function () use ($round, $now): bool {
            $this->entityManager->refresh($round);
            if (!$round->isOpen()) {
                return false;
            }
            $round->cancel($now);
            $this->entityManager->flush();

            return true;
        });

        if ($cancelled) {
            $concerned = [];
            foreach ([...$this->expectedStudents($round), ...$round->getParticipants()] as $student) {
                $concerned[(int) $student->getId()] = $student;
            }
            $this->live->publishRoundChanged($round->getTournament(), ...array_values($concerned));
            $this->live->publishRoster($round);
        }
    }

    /**
     * What a student's tournament screens say of the rounds: the open round that expects them (or
     * that they joined), else, for an hour after a pairing they took part in, their duel still
     * running, their exemption, or that the round started without them. Null: nothing to say.
     */
    public function entryFor(QuizTournament $tournament, User $student, \DateTimeImmutable $now): ?QuizTournamentRoundEntry
    {
        if (!\in_array('ROLE_STUDENT', $student->getRoles(), true)) {
            return null;
        }

        foreach ($this->rounds->findOpenForTournament($tournament) as $round) {
            if ($round->getParticipants()->contains($student)) {
                return new QuizTournamentRoundEntry($round, QuizTournamentRoundEntry::JOINED);
            }
            if ($this->isExpected($round, $student) && $this->access->canPlay($tournament, $student, $now)) {
                return new QuizTournamentRoundEntry($round, QuizTournamentRoundEntry::OPEN);
            }
        }

        foreach ($this->rounds->findPairedSinceFor($tournament, $student, $now->modify(\sprintf('-%d minutes', self::ENTRY_AFTER_PAIRING_MINUTES))) as $round) {
            $entry = $this->pairedEntry($round, $student);
            if (null !== $entry) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Where a student stands in one round - what every beat of their page answers.
     *
     * @return array{state: string, duelUrl: string|null}
     */
    public function entryState(QuizTournamentRound $round, User $student, \DateTimeImmutable $now): array
    {
        $entry = match (true) {
            $round->isOpen() && $round->getParticipants()->contains($student) => new QuizTournamentRoundEntry($round, QuizTournamentRoundEntry::JOINED),
            $round->isOpen() && $this->isExpected($round, $student) => new QuizTournamentRoundEntry($round, QuizTournamentRoundEntry::OPEN),
            null !== $round->getPairedAt() && $round->getParticipants()->contains($student) => $this->pairedEntry($round, $student),
            default => null,
        };

        return [
            'state' => null === $entry ? 'none' : $entry->state,
            'duelUrl' => null !== $entry?->duel && !$entry->duel->isFinished() ? $this->live->duelUrl($entry->duel) : null,
        ];
    }

    /**
     * The pairing itself, inside the transaction: the entrants by rating, every duel with the same
     * start, the odd one out exempted, the round marked paired.
     *
     * @param array<int, User> $participants user id => participant
     * @param list<int>        $entrantIds
     *
     * @return list<QuizTournamentDuel>
     */
    private function pair(QuizTournamentRound $round, array $participants, array $entrantIds, \DateTimeImmutable $now): array
    {
        $tournament = $round->getTournament();
        $lines = [];
        $entrants = [];
        foreach ($entrantIds as $id) {
            $lines[$id] = $this->players->findOrCreate($tournament, $participants[$id]);
            $entrants[] = new QuizTournamentRoundEntrant($id, $lines[$id]->getRating(), $lines[$id]->getRoundExemptions());
        }
        $pairs = QuizTournamentRoundPairing::pair($entrants, $this->access->cappedPairs($tournament, $entrantIds, $now));

        $ranked = $this->standings->standings($tournament, $now)->rankedRatings();
        $questions = $this->factory->sourceQuestions($tournament);
        $duels = [];
        foreach ($pairs->pairs as [$a, $b]) {
            $duel = $this->factory->create($tournament, $participants[$a], $participants[$b], QuizTournamentDuelOrigin::Round, $now, $ranked, $questions);
            $duel->setRound($round);
            $duels[] = $duel;
        }

        $exempt = null;
        if (null !== $pairs->exemptId) {
            $exempt = $participants[$pairs->exemptId];
            $lines[$pairs->exemptId]->recordRoundExemption();
        }
        $round->markPaired($now, $exempt);
        $this->entityManager->flush();

        return $duels;
    }

    /**
     * A round's restriction, read on the day: an Option's students of the class, or a group's
     * members as the lot holds them now; null for the whole class. A lot deleted since leaves an
     * empty group - nobody new is expected - never the whole class.
     *
     * $student narrows the Option's reading to one person (isExpected()).
     *
     * @return list<int>|null
     */
    private function restrictionIds(QuizTournamentRound $round, ?User $student = null): ?array
    {
        $option = $round->getRestrictedOption();
        if (null !== $option) {
            if (null !== $student) {
                $options = $this->studentOptions->findOptionsForStudent($round->getProgram(), $student);

                return \in_array($option, $options, true) ? [(int) $student->getId()] : [];
            }

            return array_map(
                static fn (User $user): int => (int) $user->getId(),
                $this->studentOptions->findStudentsForProgramAndOptions($round->getProgram(), [$option]),
            );
        }

        if (null !== $round->getRestrictedGroupIndex()) {
            return $round->restrictedGroupMemberIds() ?? [];
        }

        return null;
    }

    /**
     * The students of a class, surname then first name, keyed by id.
     *
     * @return array<int, User>
     */
    private function classStudents(Program $program): array
    {
        $students = array_values(array_filter(
            $program->getStudents()->toArray(),
            static fn (User $user): bool => \in_array('ROLE_STUDENT', $user->getRoles(), true),
        ));
        usort($students, static fn (User $a, User $b): int => [mb_strtolower((string) $a->getLastname()), mb_strtolower((string) $a->getFirstname()), $a->getId()]
            <=> [mb_strtolower((string) $b->getLastname()), mb_strtolower((string) $b->getFirstname()), $b->getId()]);

        $byId = [];
        foreach ($students as $student) {
            $byId[(int) $student->getId()] = $student;
        }

        return $byId;
    }

    private function pairedEntry(QuizTournamentRound $round, User $student): ?QuizTournamentRoundEntry
    {
        if ($round->getExempt() === $student) {
            return new QuizTournamentRoundEntry($round, QuizTournamentRoundEntry::EXEMPT);
        }
        foreach ($this->duels->findForRound($round) as $duel) {
            if ($duel->involves($student)) {
                return $duel->isFinished() ? null : new QuizTournamentRoundEntry($round, QuizTournamentRoundEntry::PLAYING, $duel);
            }
        }

        return new QuizTournamentRoundEntry($round, QuizTournamentRoundEntry::LEFT_OUT);
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function locked(QuizTournamentRound $round, callable $work): mixed
    {
        $lock = $this->lockFactory->createLock(\sprintf('quiz-tournament-round-%d', (int) $round->getId()), self::LOCK_TTL_SECONDS);
        $lock->acquire(true);
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
