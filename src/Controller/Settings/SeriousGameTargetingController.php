<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Form\SeriousGameTargetingType;
use App\Repository\SeriousGameRepository;
use App\Security\FeatureAccess;
use App\Security\Voter\SeriousGameVoter;
use App\Service\SeriousGame\SeriousGameStudentReach;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Qui joue (étudiants) » of a serious game, in Paramètres › Serious games
 * (design/validated/quiz-tournament.md §14): the Sections, filières and formations the game is
 * opened to, and whom that reaches today - the count, then the detail per formation.
 *
 * The students' only door to a game: the matrix of Paramètres › Fonctionnalités keeps the adults'
 * cells. Administrators only (SeriousGameVoter::MANAGE), a 404 for everybody else, like the rest of
 * the screen. Saving an empty targeting is allowed: it closes the game to every student.
 */
#[RequiresFeature(Feature::SeriousGames)]
class SeriousGameTargetingController extends AbstractController
{
    #[Route(path: '/settings/serious-games/{slug}/students', name: 'app_settings_serious_games_students', methods: ['GET', 'POST'])]
    public function edit(Request $request, string $slug, SeriousGameRepository $games, SeriousGameStudentReach $reach, FeatureAccess $featureAccess, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted(SeriousGameVoter::MANAGE)) {
            throw $this->createNotFoundException();
        }
        $game = $games->findOneBySlug($slug) ?? throw $this->createNotFoundException();

        $form = $this->createForm(SeriousGameTargetingType::class, $game);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'seriousGameTargetingSavedFlash');

            return $this->redirectToRoute('app_settings_serious_games_students', ['slug' => $slug]);
        }

        return $this->render('settings/serious_game/students.html.twig', [
            'game' => $game,
            'form' => $form,
            'reach' => $reach->of($game),
            'moduleLitForStudents' => $featureAccess->isEnabledForRole(Feature::SeriousGames, 'ROLE_STUDENT'),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
