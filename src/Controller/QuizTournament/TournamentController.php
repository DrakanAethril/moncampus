<?php

declare(strict_types=1);

namespace App\Controller\QuizTournament;

use App\Attribute\RequiresFeature;
use App\Entity\QuizTournament;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\QuizTournamentRepository;
use App\Security\Voter\QuizTournamentVoter;
use App\Service\QuizTournament\QuizTournamentStandingsAssembler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The quiz tournaments as their readers see them (design/validated/quiz-tournament.md §6 and §10):
 * the list of the tournaments one may read, and a tournament's page - its standings, in the
 * tournament's own dress (H « Pop », templates/quiz_tournament/_layout.html.twig).
 *
 * Who reads what is QuizTournamentVoter::VIEW alone - an eligible student, a teacher of a targeted
 * formation, the staff once it is opened, the administrators always. A refusal is a 404.
 */
#[RequiresFeature(Feature::QuizTournaments)]
class TournamentController extends AbstractController
{
    public function __construct(
        private readonly QuizTournamentRepository $tournaments,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(path: '/tournaments', name: 'app_quiz_tournaments', methods: ['GET'])]
    public function index(): Response
    {
        // An administrator reads every tournament, closed ones included; everybody else only ever
        // the opened ones, then what the voter lets them read.
        $candidates = $this->isGranted(QuizTournamentVoter::CREATE) ? $this->tournaments->findAllOrdered() : $this->tournaments->findOpened();
        $now = $this->clock->now();

        $rows = [];
        foreach ($candidates as $tournament) {
            if ($this->isGranted(QuizTournamentVoter::VIEW, $tournament)) {
                $rows[] = ['tournament' => $tournament, 'status' => $tournament->statusAt($now)];
            }
        }

        return $this->render('quiz_tournament/index.html.twig', ['rows' => $rows]);
    }

    #[Route(path: '/tournaments/{id}', name: 'app_quiz_tournament_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, QuizTournamentStandingsAssembler $assembler): Response
    {
        $tournament = $this->viewable($id);
        $now = $this->clock->now();
        $standings = $assembler->assemble($tournament, $now);

        /** @var User $reader */
        $reader = $this->getUser();

        return $this->render('quiz_tournament/show.html.twig', [
            'tournament' => $tournament,
            'status' => $tournament->statusAt($now),
            'standings' => $standings,
            'readerLine' => $standings->lineFor($reader->getId()),
            'canPlay' => $this->isGranted(QuizTournamentVoter::PLAY, $tournament),
            'activeTab' => 'standings',
        ]);
    }

    private function viewable(int $id): QuizTournament
    {
        $tournament = $this->tournaments->find($id);
        if (null === $tournament || !$this->isGranted(QuizTournamentVoter::VIEW, $tournament)) {
            throw $this->createNotFoundException();
        }

        return $tournament;
    }
}
