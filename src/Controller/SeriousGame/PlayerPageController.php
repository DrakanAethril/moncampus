<?php

declare(strict_types=1);

namespace App\Controller\SeriousGame;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Service\SeriousGame\SeriousGameAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Ma page joueur » (design/validated/serious-games.md §8): avatar, stars, comets, the showcase.
 * Read as it is by the student's teachers and the administrators; never shared with another
 * student, never reachable from outside the establishment.
 */
#[RequiresFeature(Feature::SeriousGames)]
class PlayerPageController extends AbstractController
{
    use SeriousGameControllerTrait;

    #[Route(path: '/serious-games/me', name: 'app_serious_games_me', methods: ['GET'])]
    public function me(SeriousGameAccess $access): Response
    {
        $user = $this->assertHasAPlayableGame($access);

        return $this->render('serious_game/player_page.html.twig', [
            'player' => $user,
        ]);
    }
}
