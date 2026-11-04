<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Entity\QuizTournamentChallenge;
use App\Entity\QuizTournamentDuel;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The room's gestures (design/validated/quiz-tournament.md §4.3): « Prêt » and its heartbeat, every
 * 20 seconds while the page is open (QuizTournamentChallengeRules::READY_TIMEOUT_SECONDS: a beat
 * older than 45 s means the player left), and leaving, which clears it at once.
 *
 * « Prêt » is refused by QuizTournamentRoomRules - a closed room, a tournament over, a player no
 * longer eligible, either player in another duel. Every accepted beat asks
 * QuizTournamentDuelStarter whether the duel can start: the request that sees both ready starts it.
 * Each change is published on the room's topic, so the other browser draws it at once.
 */
class QuizTournamentRooms
{
    public function __construct(
        private readonly QuizTournamentAccess $access,
        private readonly QuizTournamentDuelStarter $starter,
        private readonly QuizTournamentLive $live,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * « Prêt », or its heartbeat. Answers the duel when it has started (now or before).
     *
     * @throws QuizTournamentRefusal
     */
    public function ready(QuizTournamentChallenge $challenge, User $player, \DateTimeImmutable $now): ?QuizTournamentDuel
    {
        $this->entityManager->refresh($challenge);
        $duel = $challenge->getDuel();
        if (null !== $duel) {
            return $duel;
        }

        $refusal = QuizTournamentRoomRules::readyRefusal($this->access->roomFacts($challenge, $player, $now));
        if (null !== $refusal) {
            // A refused « Prêt » is no « Prêt »: one does not stay ready while busy elsewhere.
            $changed = $challenge->settle($now);
            if ($challenge->isReady($player, $now)) {
                $challenge->clearReady($player);
                $changed = true;
            }
            if ($changed) {
                $this->entityManager->flush();
                $this->live->publishReadiness($challenge, $now);
            }

            throw new QuizTournamentRefusal($refusal->labelKey(), ['%name%' => QuizTournamentChallenges::firstName($challenge->opponentOf($player) ?? $player)]);
        }

        $wasReady = $challenge->isReady($player, $now);
        $challenge->markReady($player, $now);
        $this->entityManager->flush();
        if (!$wasReady) {
            $this->live->publishReadiness($challenge, $now);
        }

        return $this->starter->startIfReady($challenge, $now);
    }

    /** Leaving the page, or « Quitter la salle »: one is no longer ready. */
    public function leave(QuizTournamentChallenge $challenge, User $player, \DateTimeImmutable $now): void
    {
        $this->entityManager->refresh($challenge);
        if (null !== $challenge->getDuel()) {
            return;
        }

        $challenge->clearReady($player);
        $challenge->settle($now);
        $this->entityManager->flush();
        $this->live->publishReadiness($challenge, $now);
    }

    /**
     * The room as this player reads it now - what the page draws first and what every beat
     * answers.
     *
     * @return array{open: bool, closesAt: string|null, me: bool, opponent: bool, duelUrl: string|null}
     */
    public function state(QuizTournamentChallenge $challenge, User $player, \DateTimeImmutable $now): array
    {
        $duel = $challenge->getDuel();
        $opponent = $challenge->opponentOf($player);

        return [
            'open' => $challenge->isRoomOpen($now),
            'closesAt' => $challenge->getRoomClosesAt()?->format(\DATE_ATOM),
            'me' => $challenge->isReady($player, $now),
            'opponent' => null !== $opponent && $challenge->isReady($opponent, $now),
            'duelUrl' => null !== $duel ? $this->live->duelUrl($duel) : null,
        ];
    }
}
