<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\QuizTournament;
use App\Entity\QuizTournamentChallenge;
use App\Entity\User;
use App\Repository\QuizTournamentChallengeRepository;
use App\Repository\QuizTournamentPlayerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The challenges' gestures (design/validated/quiz-tournament.md §4.1, §4.2 and §7): send, accept,
 * refuse - each one reads its facts again here, server-side, whatever the page offered, and refuses
 * with the sentence the screen shows (QuizTournamentRefusal).
 *
 * - **Send**: QuizTournamentChallengeRules on fresh facts; the challenge is stamped `ladder` when the
 *   challenged is one of the five ranked players nearest the challenger's rating *now* (§6.2); the
 *   challenger's line is created if this is their first gesture.
 * - **Accept**: the challenged alone, while it is pending; both players still eligible, the
 *   tournament still running (§2: eligibility is read at every acceptance). Opens the room, 24 h.
 * - **Refuse**: the challenged alone, while it is pending, without a reason - which starts the 48 h
 *   during which the challenger may not challenge them again.
 *
 * The three run under one lock per tournament: two challenges sent at once by the same person, or
 * to somebody at their fifth, cannot both pass the count. Nothing is sent to anybody (§1, decision
 * 7): the counters of the two tournament screens are updated, and that is all.
 *
 * « Mes défis » expires lazily: board() rewrites, when it reads them, the rows whose 24 hours (or
 * whose tournament) are over - no task needed.
 */
class QuizTournamentChallenges
{
    private const int LOCK_TTL_SECONDS = 15;

    public function __construct(
        private readonly QuizTournamentAccess $access,
        private readonly QuizTournamentStandingsAssembler $standings,
        private readonly QuizTournamentChallengeRepository $challenges,
        private readonly QuizTournamentPlayerRepository $players,
        private readonly QuizTournamentLive $live,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * @throws QuizTournamentRefusal
     */
    public function send(QuizTournament $tournament, User $challenger, User $challenged, \DateTimeImmutable $now): QuizTournamentChallenge
    {
        $challenge = $this->locked($tournament, function () use ($tournament, $challenger, $challenged, $now): QuizTournamentChallenge {
            $refusal = QuizTournamentChallengeRules::firstRefusal($this->access->challengeFacts($tournament, $challenger, $challenged, $now), $now);
            if (null !== $refusal) {
                throw new QuizTournamentRefusal($refusal->labelKey(), ['%name%' => self::firstName($challenged)]);
            }

            $player = $this->players->findOrCreate($tournament, $challenger);
            $ladder = QuizTournamentStanding::isLadderOpponent(
                (int) $challenger->getId(),
                $player->getRating(),
                (int) $challenged->getId(),
                $this->standings->standings($tournament, $now)->rankedRatings(),
            );

            $challenge = new QuizTournamentChallenge($tournament, $challenger, $challenged, $ladder, $now);
            $this->entityManager->persist($challenge);
            $this->entityManager->flush();

            return $challenge;
        });

        $this->live->publishCounters($tournament, $now, $challenged);

        return $challenge;
    }

    /**
     * @throws QuizTournamentRefusal
     */
    public function accept(QuizTournamentChallenge $challenge, User $user, \DateTimeImmutable $now): QuizTournamentChallenge
    {
        $tournament = $challenge->getTournament();
        $this->locked($tournament, function () use ($challenge, $user, $tournament, $now): void {
            $this->entityManager->refresh($challenge);
            $this->assertAwaitingAnswerFrom($challenge, $user, $now);

            if (!$tournament->isInProgress($now)) {
                throw new QuizTournamentRefusal('quizTournamentRefusalNotInProgress');
            }
            if (!$this->access->canPlay($tournament, $user, $now)) {
                throw new QuizTournamentRefusal('quizTournamentRefusalChallengerNotEligible');
            }
            if (!$this->access->isEligible($tournament, $challenge->getChallenger())) {
                throw new QuizTournamentRefusal('quizTournamentRefusalChallengedNotEligible', ['%name%' => self::firstName($challenge->getChallenger())]);
            }

            $challenge->accept($now);
            $this->players->findOrCreate($tournament, $user);
            $this->entityManager->flush();
        });

        $this->live->publishCounters($tournament, $now, $challenge->getChallenger(), $challenge->getChallenged());

        return $challenge;
    }

    /**
     * @throws QuizTournamentRefusal
     */
    public function refuse(QuizTournamentChallenge $challenge, User $user, \DateTimeImmutable $now): QuizTournamentChallenge
    {
        $tournament = $challenge->getTournament();
        $this->locked($tournament, function () use ($challenge, $user, $now): void {
            $this->entityManager->refresh($challenge);
            $this->assertAwaitingAnswerFrom($challenge, $user, $now);
            $challenge->refuse($now);
            $this->entityManager->flush();
        });

        $this->live->publishCounters($tournament, $now, $challenge->getChallenged());

        return $challenge;
    }

    /**
     * « Mes défis » of this person, read now - the rows it shows whose time is over rewritten on the
     * way (expired, missed).
     */
    public function board(QuizTournament $tournament, User $user, \DateTimeImmutable $now): QuizTournamentChallengeBoard
    {
        $challenges = $this->challenges->findForBoard($tournament, $user, $now->modify(\sprintf('-%d hours', 2 * QuizTournamentChallengeBoard::OUTCOME_HOURS)));

        $settled = false;
        foreach ($challenges as $challenge) {
            $settled = $challenge->settle($now) || $settled;
        }
        if ($settled) {
            $this->entityManager->flush();
        }

        return QuizTournamentChallengeBoard::of($challenges, $user, $now);
    }

    /**
     * The facts of the Défier sheet: may this person challenge that one now, and if not, why.
     */
    public function facts(QuizTournament $tournament, User $challenger, User $challenged, \DateTimeImmutable $now): QuizTournamentChallengeFacts
    {
        return $this->access->challengeFacts($tournament, $challenger, $challenged, $now);
    }

    public static function firstName(User $user): string
    {
        return $user->getFirstname() ?? $user->getDisplayName() ?? $user->getUsername();
    }

    private function assertAwaitingAnswerFrom(QuizTournamentChallenge $challenge, User $user, \DateTimeImmutable $now): void
    {
        if ($challenge->getChallenged() !== $user || !$challenge->isPending($now)) {
            if ($challenge->settle($now)) {
                $this->entityManager->flush();
            }

            throw new QuizTournamentRefusal('quizTournamentChallengeNoLongerPending');
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $gesture
     *
     * @return T
     */
    private function locked(QuizTournament $tournament, callable $gesture): mixed
    {
        $lock = $this->lockFactory->createLock(\sprintf('quiz-tournament-challenges-%d', (int) $tournament->getId()), self::LOCK_TTL_SECONDS);
        $lock->acquire(true);
        try {
            return $gesture();
        } finally {
            $lock->release();
        }
    }
}
