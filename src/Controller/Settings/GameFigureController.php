<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Attribute\RequiresFeature;
use App\Entity\GameFigure;
use App\Enum\Feature;
use App\Enum\GameTrack;
use App\Repository\GameFigureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The catalogue of historical figures a pseudonym is drawn from, filière by filière.
 *
 * The catalogue shipped with the feature is an **amorce** - ten to twelve names per filière, taken
 * from the design's own list - and every row of it arrives unreviewed on purpose. A name is easy; a
 * correct one-line notice is documentary work, and **a wrong notice in a device that claims to be
 * pedagogical is worse than no notice at all**. This screen is where that work gets done: it shows
 * the tally per filière, lets an administrator correct, add and retire entries, and carries the
 * « relue » tick that says a human has actually read the line.
 *
 * The design asks for sixty entries per filière - thirty to cover a promo, and enough margin that
 * the last student to choose still gets a real choice.
 */
#[IsGranted('ROLE_ADMIN')]
#[RequiresFeature(Feature::Game)]
class GameFigureController extends AbstractController
{
    #[Route(path: '/settings/game/figures', name: 'app_settings_game_figures', methods: ['GET'])]
    public function index(Request $request, GameFigureRepository $figures): Response
    {
        $track = GameTrack::tryFrom((string) $request->query->get('track')) ?? GameTrack::Slam;

        $tally = [];
        foreach (GameTrack::cases() as $candidate) {
            $tally[$candidate->value] = $figures->tally($candidate);
        }

        return $this->render('settings/game_figures.html.twig', [
            'tracks' => GameTrack::cases(),
            'track' => $track,
            'figures' => $figures->forTrack($track),
            'tally' => $tally,
        ]);
    }

    #[Route(path: '/settings/game/figures/new', name: 'app_settings_game_figures_new', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager): Response
    {
        $track = $this->guard($request, 'game_figure_new');

        $surname = trim((string) $request->request->get('surname'));
        $fullName = trim((string) $request->request->get('full_name'));

        if ('' === $surname || '' === $fullName) {
            $this->addFlash('error', 'gameFigureNameRequiredMessage');

            return $this->redirectToRoute('app_settings_game_figures', ['track' => $track->value]);
        }

        $figure = (new GameFigure($track, $surname, $fullName))
            ->setDates(trim((string) $request->request->get('dates')))
            ->setNotice(trim((string) $request->request->get('notice')))
            // Added by hand, by somebody who typed the notice: reviewed by construction.
            ->setReviewed(true);

        $entityManager->persist($figure);
        $entityManager->flush();

        $this->addFlash('success', 'gameFigureCreatedFlashMessage');

        return $this->redirectToRoute('app_settings_game_figures', ['track' => $track->value]);
    }

    #[Route(path: '/settings/game/figures/{id}/edit', name: 'app_settings_game_figures_edit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function edit(int $id, Request $request, GameFigureRepository $figures, EntityManagerInterface $entityManager): Response
    {
        $this->guard($request, 'game_figure_edit');

        $figure = $figures->find($id) ?? throw $this->createNotFoundException();

        $figure
            ->setSurname(trim((string) $request->request->get('surname')) ?: $figure->getSurname())
            ->setFullName(trim((string) $request->request->get('full_name')) ?: $figure->getFullName())
            ->setDates(trim((string) $request->request->get('dates')))
            ->setNotice(trim((string) $request->request->get('notice')))
            ->setReviewed($request->request->getBoolean('reviewed'))
            ->setActive($request->request->getBoolean('active'))
        ;

        $entityManager->flush();
        $this->addFlash('success', 'gameFigureSavedFlashMessage');

        return $this->redirectToRoute('app_settings_game_figures', ['track' => $figure->getTrack()->value]);
    }

    private function guard(Request $request, string $token): GameTrack
    {
        if (!$this->isCsrfTokenValid($token, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        return GameTrack::tryFrom((string) $request->request->get('track')) ?? GameTrack::Slam;
    }
}
