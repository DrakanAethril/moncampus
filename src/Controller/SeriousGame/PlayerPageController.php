<?php

declare(strict_types=1);

namespace App\Controller\SeriousGame;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Security\Voter\SeriousGameVoter;
use App\SeriousGame\Avatar\SeriousGameAvatars;
use App\SeriousGame\SeriousGamePlayerPage;
use App\Service\SeriousGame\SeriousGameAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Ma page joueur » (design/validated/serious-games.md §8): avatar, stars, comets, the parties
 * played, the showcase. Read as it is by the student's teachers and the administrators
 * (ResultsController::student()); never shared with another student, never reachable from outside
 * the establishment - there is no public address and no share link.
 *
 * Wearing a pin is the showcase's one gesture, and a student's: nothing is exchanged, nothing bought.
 */
#[RequiresFeature(Feature::SeriousGames)]
class PlayerPageController extends AbstractController
{
    use SeriousGameControllerTrait;

    #[Route(path: '/serious-games/me', name: 'app_serious_games_me', methods: ['GET'])]
    public function me(SeriousGameAccess $access, SeriousGamePlayerPage $page): Response
    {
        $user = $this->assertHasAPlayableGame($access);

        return $this->render('serious_game/player_page.html.twig', $page->build($user, true));
    }

    #[Route(path: '/serious-games/me/pin', name: 'app_serious_games_me_pin', methods: ['POST'])]
    public function wearPin(Request $request, SeriousGameAccess $access, SeriousGameAvatars $avatars): Response
    {
        $user = $this->assertHasAPlayableGame($access);
        $this->assertCsrf($request);
        if (!$this->isGranted(SeriousGameVoter::COLLECT)) {
            throw $this->createNotFoundException();
        }

        $pin = $request->request->getString('pin');
        try {
            $avatars->save($user, ['pin' => '' === $pin ? null : $pin] + $avatars->configFor($user));
            $this->addFlash('success', '' === $pin ? 'seriousGamePinRemovedFlash' : 'seriousGamePinWornFlash');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_serious_games_me', ['_fragment' => 'vitrine']);
    }
}
