<?php

declare(strict_types=1);

namespace App\Controller\SeriousGame;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The serious games' former avatar editor. The avatar left the module for « Mon profil »
 * (design/validated/quiz-tournament.md §16): the editor is now the profile's creation tunnel
 * (App\Controller\ProfileAvatarController), and this old address answers a 301 there - for anybody,
 * module lit or not, since a bookmark should not end on a 404. Its save route went with it.
 */
class AvatarController extends AbstractController
{
    #[Route(path: '/serious-games/avatar', name: 'app_serious_games_avatar', methods: ['GET'])]
    public function moved(): RedirectResponse
    {
        return $this->redirectToRoute('app_profile_avatar_draw', [], Response::HTTP_MOVED_PERMANENTLY);
    }
}
