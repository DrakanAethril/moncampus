<?php

declare(strict_types=1);

namespace App\Controller\SeriousGame;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\SeriousGameRepository;
use App\Security\Voter\SeriousGameVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The reading of the results (design/validated/serious-games.md §3.2, §8): the classes a teacher
 * teaches, every class for the administrator, figures without names for staff and staff-lead.
 * A tutor or an external account reads nothing.
 */
#[RequiresFeature(Feature::SeriousGames)]
class ResultsController extends AbstractController
{
    use SeriousGameControllerTrait;

    #[Route(path: '/serious-games/results', name: 'app_serious_games_results', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertReader();

        return $this->render('serious_game/results/index.html.twig');
    }

    #[Route(path: '/serious-games/results/{program}/{slug}', name: 'app_serious_games_results_class', requirements: ['program' => '\d+'], methods: ['GET'])]
    public function class(Program $program, string $slug, SeriousGameRepository $games): Response
    {
        $game = $games->findOneBySlug($slug);
        if (null === $game || !$this->isGranted(SeriousGameVoter::READ_CLASS, $program)) {
            throw $this->createNotFoundException();
        }

        return $this->render('serious_game/results/class.html.twig', [
            'program' => $program,
            'game' => $game,
        ]);
    }

    #[Route(path: '/serious-games/results/student/{user}', name: 'app_serious_games_results_student', requirements: ['user' => '\d+'], methods: ['GET'])]
    public function student(User $user): Response
    {
        if (!$this->isGranted(SeriousGameVoter::READ_STUDENT, $user) || $user === $this->getUser()) {
            throw $this->createNotFoundException();
        }

        return $this->render('serious_game/results/student.html.twig', [
            'player' => $user,
        ]);
    }

    /** Teachers, staff and administrators: the three readings of the results screen. */
    private function assertReader(): void
    {
        if (!$this->isGranted('ROLE_TEACHER') && !$this->isGranted(SeriousGameVoter::READ_AGGREGATE)) {
            throw $this->createNotFoundException();
        }
    }
}
