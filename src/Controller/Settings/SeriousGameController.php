<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Repository\SeriousGameRepository;
use App\Security\Voter\SeriousGameVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Paramètres › Serious games (design/validated/serious-games.md §8, « Administration »): the games,
 * their publication, the answer kinds they accept, their question templates and their health.
 * Administrators only.
 */
#[RequiresFeature(Feature::SeriousGames)]
class SeriousGameController extends AbstractController
{
    #[Route(path: '/settings/serious-games', name: 'app_settings_serious_games', methods: ['GET'])]
    public function index(SeriousGameRepository $games): Response
    {
        if (!$this->isGranted(SeriousGameVoter::MANAGE)) {
            throw $this->createNotFoundException();
        }

        return $this->render('settings/serious_game/index.html.twig', [
            'games' => $games->findAllOrdered(),
        ]);
    }
}
