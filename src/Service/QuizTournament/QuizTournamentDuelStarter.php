<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\QuizTournamentChallenge;
use App\Entity\QuizTournamentDuel;
use App\Enum\QuizTournamentDuelOrigin;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The start of a duel born of a challenge (design/validated/quiz-tournament.md §4.3 and §5.1), once
 * both players are ready in the room. Called by every « Prêt » and every heartbeat: whichever
 * request sees both ready first starts it, and **only one** does.
 *
 * Under a lock on the room (LOCK_DSN, shared by the containers) and inside one transaction, the
 * challenge row read again FOR UPDATE:
 * 1. a challenge that already has its duel answers that duel - the other player's request, a
 *    second tab, a retry;
 * 2. QuizTournamentRoomRules::shouldStart() on facts read now - both still ready (a heartbeat under
 *    45 s), both still eligible, the room and the tournament open, neither playing elsewhere;
 * 3. the duel, player A the challenger, made by QuizTournamentDuelFactory (shared with the rounds
 *    in class); the challenge marked played.
 *
 * Then « start » is published on the room's topic and both counters move. The questions are not
 * served yet: the duel screen serves each one (QuizTournamentDuelQuestion::serve()).
 */
class QuizTournamentDuelStarter
{
    private const int LOCK_TTL_SECONDS = 30;

    public function __construct(
        private readonly QuizTournamentAccess $access,
        private readonly QuizTournamentStandingsAssembler $standings,
        private readonly QuizTournamentDuelFactory $factory,
        private readonly QuizTournamentLive $live,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /** The duel of this room - started now if both are ready, already started, or null (not yet). */
    public function startIfReady(QuizTournamentChallenge $challenge, \DateTimeImmutable $now): ?QuizTournamentDuel
    {
        $lock = $this->lockFactory->createLock(\sprintf('quiz-tournament-room-%d', (int) $challenge->getId()), self::LOCK_TTL_SECONDS);
        $lock->acquire(true);
        try {
            /** @var array{0: QuizTournamentDuel|null, 1: bool} $outcome */
            $outcome = $this->entityManager->wrapInTransaction(function () use ($challenge, $now): array {
                $this->entityManager->refresh($challenge, LockMode::PESSIMISTIC_WRITE);
                $existing = $challenge->getDuel();
                if (null !== $existing) {
                    return [$existing, false];
                }

                if (!QuizTournamentRoomRules::shouldStart($this->access->roomFacts($challenge, $challenge->getChallenger(), $now))) {
                    return [null, false];
                }

                return [$this->create($challenge, $now), true];
            });
        } finally {
            $lock->release();
        }

        [$duel, $created] = $outcome;
        if (null !== $duel && $created) {
            $this->live->publishStart($challenge, $duel);
            $this->live->publishCounters($challenge->getTournament(), $now, $challenge->getChallenger(), $challenge->getChallenged());
        }

        return $duel;
    }

    private function create(QuizTournamentChallenge $challenge, \DateTimeImmutable $now): QuizTournamentDuel
    {
        $tournament = $challenge->getTournament();
        $duel = $this->factory->create(
            $tournament,
            $challenge->getChallenger(),
            $challenge->getChallenged(),
            QuizTournamentDuelOrigin::Challenge,
            $now,
            $this->standings->standings($tournament, $now)->rankedRatings(),
        );

        $challenge->markPlayed($duel);
        $this->entityManager->flush();

        return $duel;
    }
}
