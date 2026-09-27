<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Enum\ExternalService;
use App\Enum\Feature;
use App\Enum\PlatformActivityType;
use App\OAuth\ConnectorUrls;
use App\Repository\OAuthGrantRepository;
use App\Security\ExternalServicePasswords;
use App\Service\PlatformActivityRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The « Claude » card of « Mon profil »: the address to paste into claude.ai, and the connections
 * made with it, each one closable.
 *
 * A controller of its own rather than two more actions on App\Controller\ProfileController, which
 * is fat enough; the card is rendered into the profile page as a fragment (`card()`, no route).
 */
#[IsGranted('ROLE_USER')]
#[RequiresFeature(Feature::ClaudeConnector)]
class ProfileClaudeConnectorController extends AbstractController
{
    public function card(OAuthGrantRepository $grants, ConnectorUrls $urls, ClockInterface $clock, ExternalServicePasswords $servicePasswords): Response
    {
        return $this->render('profile/_claude_connector_card.html.twig', [
            'connectorUrl' => $urls->mcpUrl(),
            'connections' => $grants->findLiveFor($this->currentUser(), $clock->now()),
            'service' => ExternalService::ClaudeConnector,
            'servicePassword' => $servicePasswords->find($this->currentUser(), ExternalService::ClaudeConnector),
        ]);
    }

    /**
     * Closes one connection: its access token stops opening /mcp on the next call, and its refresh
     * token buys nothing more (App\OAuth\TokenIssuer). Claude then asks the teacher to reconnect.
     *
     * Their own, checked here rather than trusted from the URL.
     */
    #[Route(path: '/profile/claude/connections/{id}/revoke', name: 'app_profile_claude_connection_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoke(
        Request $request,
        int $id,
        OAuthGrantRepository $grants,
        EntityManagerInterface $entityManager,
        PlatformActivityRecorder $activity,
        ClockInterface $clock,
    ): Response {
        if (!$this->isCsrfTokenValid('profile_claude_connection_revoke', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $grant = $grants->find($id);
        if (null === $grant || $grant->getUser() !== $this->currentUser()) {
            throw $this->createNotFoundException();
        }

        if (!$grant->isRevoked()) {
            $grant->revoke($clock->now());
            $entityManager->flush();
            $activity->record(PlatformActivityType::ClaudeConnectorRevoked, $this->currentUser(), $request, [
                'grant' => (string) $grant->getId(),
                'client' => $grant->getClient()->getClientName(),
            ]);
        }

        $this->addFlash('success', 'claudeConnectionRevokedFlashMessage');

        return $this->redirectToRoute('app_profile');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }
}
