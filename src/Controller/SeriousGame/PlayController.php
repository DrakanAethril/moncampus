<?php

declare(strict_types=1);

namespace App\Controller\SeriousGame;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Repository\SeriousGameRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The host page of a game (design/validated/serious-games.md §6.1): the game's static package in a
 * sandboxed frame with no `allow-same-origin`, talking to the platform only through `postMessage`.
 */
#[RequiresFeature(Feature::SeriousGames)]
class PlayController extends AbstractController
{
    use SeriousGameControllerTrait;

    #[Route(path: '/serious-games/play/{slug}', name: 'app_serious_games_play', methods: ['GET'])]
    public function play(SeriousGameRepository $games, string $slug): Response
    {
        $game = $this->playableGame($games, $slug);

        return $this->render('serious_game/play.html.twig', [
            'game' => $game,
        ]);
    }
}
