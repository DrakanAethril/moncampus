<?php

declare(strict_types=1);

namespace App\Controller\QuizTournament;

use App\Entity\QuizTournament;
use App\Entity\QuizTournamentChallenge;
use App\Entity\User;
use App\Security\Voter\QuizTournamentVoter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the tournament's player screens share: the tournament one may read
 * (QuizTournamentVoter::VIEW), and a challenge of that tournament one takes part in - anything else
 * is a 404, never a 403, like the rest of the tournament.
 */
trait QuizTournamentControllerTrait
{
    private function viewableTournament(int $id, EntityManagerInterface $entityManager): QuizTournament
    {
        $tournament = $entityManager->getRepository(QuizTournament::class)->find($id);
        if (null === $tournament || !$this->isGranted(QuizTournamentVoter::VIEW, $tournament)) {
            throw $this->createNotFoundException();
        }

        return $tournament;
    }

    private function playableTournament(int $id, EntityManagerInterface $entityManager): QuizTournament
    {
        $tournament = $this->viewableTournament($id, $entityManager);
        if (!$this->isGranted(QuizTournamentVoter::PLAY, $tournament)) {
            throw $this->createNotFoundException();
        }

        return $tournament;
    }

    /** A challenge of this tournament the current person is one of the two players of. */
    private function ownChallenge(QuizTournament $tournament, int $challengeId, EntityManagerInterface $entityManager): QuizTournamentChallenge
    {
        $challenge = $entityManager->getRepository(QuizTournamentChallenge::class)->find($challengeId);
        if (null === $challenge || $challenge->getTournament() !== $tournament || !$challenge->involves($this->player())) {
            throw $this->createNotFoundException();
        }

        return $challenge;
    }

    private function player(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
