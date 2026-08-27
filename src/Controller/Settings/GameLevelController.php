<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Attribute\RequiresFeature;
use App\Entity\GameLevelLabel;
use App\Enum\Feature;
use App\Enum\GameTrack;
use App\Repository\GameLevelLabelRepository;
use App\Service\Game\GameLevelBoard;
use App\Service\Game\GameLevels;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The wording of the six levels, filière by filière (design's screen 3).
 *
 * **Only the words are editable here.** The XP thresholds are common to the whole establishment and
 * live in App\Service\Game\GameLevels: the ring an avatar carries is drawn on every screen of the
 * application, and a threshold that moved from one formation to the next would make it mean
 * nothing. That is why this screen has twenty-four text fields and no number.
 *
 * Emptying a cell is a legitimate act and deletes the row: what a reader then sees is the generic
 * « Niveau 3 », never a blank.
 */
#[IsGranted('ROLE_ADMIN')]
#[RequiresFeature(Feature::Game)]
class GameLevelController extends AbstractController
{
    #[Route(path: '/settings/game/levels', name: 'app_settings_game_levels', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        GameLevelLabelRepository $repository,
        GameLevelBoard $board,
        EntityManagerInterface $entityManager,
    ): Response {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('game_level_labels', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $this->save($request, $repository, $entityManager);
            $board->reset();
            $this->addFlash('success', 'gameLevelLabelsSavedFlashMessage');

            return $this->redirectToRoute('app_settings_game_levels');
        }

        return $this->render('settings/game_levels.html.twig', [
            'tracks' => GameTrack::cases(),
            'levels' => GameLevels::all(),
            'matrix' => $board->matrix(),
        ]);
    }

    private function save(Request $request, GameLevelLabelRepository $repository, EntityManagerInterface $entityManager): void
    {
        /** @var array<string, array<array-key, string>> $submitted */
        $submitted = $request->request->all('labels');

        $stored = [];
        foreach ($repository->findAll() as $row) {
            $stored[$row->getTrack()->value.'|'.$row->getLevel()] = $row;
        }

        foreach (GameTrack::cases() as $track) {
            foreach (GameLevels::all() as $level) {
                $key = $track->value.'|'.$level->level;
                $value = trim((string) ($submitted[$track->value][(string) $level->level] ?? ''));
                $row = $stored[$key] ?? null;

                if ('' === $value) {
                    // Deliberately a delete rather than an empty row: the reader's fallback is the
                    // generic wording, and an empty string stored would win over it.
                    if (null !== $row) {
                        $entityManager->remove($row);
                    }

                    continue;
                }

                if (null === $row) {
                    $entityManager->persist(new GameLevelLabel($track, $level->level, $value));

                    continue;
                }

                $row->setLabel($value);
            }
        }

        $entityManager->flush();
    }
}
