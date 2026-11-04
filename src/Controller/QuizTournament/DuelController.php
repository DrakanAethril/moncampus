<?php

declare(strict_types=1);

namespace App\Controller\QuizTournament;

use App\Attribute\RequiresFeature;
use App\Entity\QuizTournamentDuel;
use App\Enum\Feature;
use App\Service\QuizTournament\QuizTournamentLive;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A duel (design/validated/quiz-tournament.md §5, H-question). Where both rooms' pages go once
 * QuizTournamentDuelStarter has created it. Only its two players reach it.
 *
 * **A placeholder until the duel screen is built** (the second half of lot 3): the route, its name
 * and its access are the contract the room relies on; the page itself only says the duel starts.
 */
#[RequiresFeature(Feature::QuizTournaments)]
class DuelController extends AbstractController
{
    use QuizTournamentControllerTrait;

    #[Route(path: '/tournaments/{id}/duels/{duel}', name: 'app_quiz_tournament_duel', requirements: ['id' => '\d+', 'duel' => '\d+'], methods: ['GET'])]
    public function show(int $id, int $duel, Request $request, EntityManagerInterface $entityManager, ClockInterface $clock, QuizTournamentLive $live, TranslatorInterface $translator): Response
    {
        $tournament = $this->viewableTournament($id, $entityManager);
        $row = $entityManager->getRepository(QuizTournamentDuel::class)->find($duel);
        $player = $this->player();
        if (null === $row || $row->getTournament() !== $tournament || !$row->involves($player)) {
            throw $this->createNotFoundException();
        }

        return $this->render('quiz_tournament/duel.html.twig', [
            'tournament' => $tournament,
            'duel' => $row,
            'opponent' => $row->opponentOf($player),
            'tournamentLive' => $live->screen($request, $tournament, $player, $clock->now()),
            'activeTab' => 'duels',
            'tournamentCrumbs' => [['label' => $translator->trans('quizTournamentDuelHeading'), 'url' => $this->generateUrl('app_quiz_tournament_duel', ['id' => $id, 'duel' => $duel])]],
        ]);
    }
}
