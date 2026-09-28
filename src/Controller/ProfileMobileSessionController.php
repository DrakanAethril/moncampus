<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\MobileSessionRepository;
use App\Security\MobileSessions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Applications mobiles connectées » in « Mon profil »: every phone still holding a session
 * (App\Security\MobileSessions), each one closable - what to do about a lost phone.
 *
 * Not behind a feature, like the rest of the profile: signing one's own phone out is how somebody
 * uses their account, not something an establishment runs. The card is a fragment (`card()`, no
 * route), rendered only when there is a phone to list.
 */
#[IsGranted('ROLE_USER')]
class ProfileMobileSessionController extends AbstractController
{
    public function card(MobileSessionRepository $sessions, ClockInterface $clock): Response
    {
        $live = $sessions->findLiveFor($this->currentUser(), $clock->now());

        if ([] === $live) {
            return new Response('');
        }

        return $this->render('profile/_mobile_sessions_card.html.twig', ['sessions' => $live]);
    }

    /**
     * The phone keeps its JWT until the hour is up - it is stateless and cannot be recalled - but
     * its refresh token buys nothing more, so it is signed out at the latest an hour from now.
     */
    #[Route(path: '/profile/mobile-sessions/{id}/revoke', name: 'app_profile_mobile_session_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoke(Request $request, int $id, MobileSessions $sessions): Response
    {
        if (!$this->isCsrfTokenValid('profile_mobile_session_revoke', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$sessions->revokeForUser($this->currentUser(), $id)) {
            throw $this->createNotFoundException();
        }

        $this->addFlash('success', 'mobileSessionRevokedFlashMessage');

        return $this->redirectToRoute('app_profile');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }
}
