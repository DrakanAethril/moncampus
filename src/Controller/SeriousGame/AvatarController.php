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
 * The avatar editor (design/validated/serious-games.md §7). The avatar is drawn in the serious
 * games only; everywhere else Formatool keeps the photo and the initials.
 */
#[RequiresFeature(Feature::SeriousGames)]
class AvatarController extends AbstractController
{
    use SeriousGameControllerTrait;

    #[Route(path: '/serious-games/avatar', name: 'app_serious_games_avatar', methods: ['GET'])]
    public function edit(SeriousGameAccess $access): Response
    {
        $this->assertHasAPlayableGame($access);

        return $this->render('serious_game/avatar.html.twig');
    }
}
