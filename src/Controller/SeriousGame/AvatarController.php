<?php

declare(strict_types=1);

namespace App\Controller\SeriousGame;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\SeriousGame\Avatar\SeriousGameAvatars;
use App\Service\SeriousGame\SeriousGameAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The avatar editor (design/validated/serious-games.md §7). The avatar is drawn in the serious
 * games only; everywhere else Formatool keeps the photo and the initials.
 *
 * The page draws nothing itself: `sg_avatar_editor_controller.js` builds the editor from the shared
 * module's catalogue and saves the config here, as JSON. The server keeps only the values the
 * editor offers and refuses a pin the person has not obtained.
 */
#[RequiresFeature(Feature::SeriousGames)]
class AvatarController extends AbstractController
{
    use SeriousGameControllerTrait;

    #[Route(path: '/serious-games/avatar', name: 'app_serious_games_avatar', methods: ['GET'])]
    public function edit(SeriousGameAccess $access, SeriousGameAvatars $avatars): Response
    {
        $user = $this->assertHasAPlayableGame($access);

        return $this->render('serious_game/avatar.html.twig', [
            'config' => $avatars->configFor($user),
            'saved' => $avatars->hasOne($user),
            'pins' => $avatars->heldPins($user),
            'collects' => $access->canCollect($user),
        ]);
    }

    #[Route(path: '/serious-games/avatar', name: 'app_serious_games_avatar_save', methods: ['POST'])]
    public function save(Request $request, SeriousGameAccess $access, SeriousGameAvatars $avatars): JsonResponse
    {
        $user = $this->assertHasAPlayableGame($access);
        $this->assertCsrf($request);

        $config = json_decode($request->getContent(), true);
        if (!\is_array($config)) {
            return $this->json(['error' => 'seriousGameAvatarInvalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            return $this->json(['config' => $avatars->save($user, $config)]);
        } catch (\DomainException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
