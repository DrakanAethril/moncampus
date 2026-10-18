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
 * The catalogue of the serious games (design/validated/serious-games.md §8): the games playable for
 * the person, one shelf, no recommendation. A student reaches it by its address alone - there is
 * no link in their menus (§1.12).
 */
#[RequiresFeature(Feature::SeriousGames)]
class CatalogController extends AbstractController
{
    use SeriousGameControllerTrait;

    #[Route(path: '/serious-games', name: 'app_serious_games', methods: ['GET'])]
    public function index(SeriousGameAccess $access): Response
    {
        $user = $this->assertHasAPlayableGame($access);

        return $this->render('serious_game/catalog.html.twig', [
            'games' => $access->playableGames($user),
            'collects' => $access->canCollect($user),
        ]);
    }
}
