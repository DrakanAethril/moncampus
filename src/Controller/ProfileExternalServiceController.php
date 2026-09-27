<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\OAuth\AuthorizeController;
use App\Entity\User;
use App\Enum\ExternalService;
use App\Security\ExternalServicePasswordRefused;
use App\Security\ExternalServicePasswords;
use App\Security\FeatureAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Mon profil » > the password of an external service: choose it, change it, remove it
 * (App\Security\ExternalServicePasswords holds the rules).
 *
 * Choosing one is trusted to the signed-in session alone, like changing the account password
 * (App\Controller\ProfileController::changePassword()): whoever holds the session already holds
 * more than a service password would give.
 *
 * Each service's feature is checked by hand rather than by #[RequiresFeature], because the route
 * serves every service and the service is a route parameter.
 */
#[IsGranted('ROLE_USER')]
class ProfileExternalServiceController extends AbstractController
{
    public function __construct(
        private readonly ExternalServicePasswords $passwords,
        private readonly FeatureAccess $featureAccess,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/profile/external-services/{service}/password', name: 'app_profile_external_service_password', methods: ['POST'])]
    public function setPassword(Request $request, string $service): Response
    {
        $service = $this->service($service);
        $this->assertCsrf($request, $service);

        $password = (string) $request->request->get('password', '');
        if ($password !== (string) $request->request->get('confirmation', '')) {
            $this->addFlash('error', 'newPasswordMismatchMessage');

            return $this->backToProfile($service);
        }

        try {
            $this->passwords->set($this->currentUser(), $service, $password);
        } catch (ExternalServicePasswordRefused $refusal) {
            $this->addFlash('error', $refusal->messageKey);

            return $this->backToProfile($service);
        }
        $this->entityManager->flush();

        $this->addFlash('success', 'externalServicePasswordSavedFlashMessage');

        // Somebody sent here from the consent screen goes back to it: the authorisation they had
        // started can now be given. Kept in the session by that screen, never read from the URL.
        $return = $request->getSession()->remove(AuthorizeController::RETURN_SESSION_KEY);
        if (\is_string($return) && '' !== $return) {
            return new RedirectResponse($return);
        }

        return $this->backToProfile($service);
    }

    #[Route(path: '/profile/external-services/{service}/password/remove', name: 'app_profile_external_service_password_remove', methods: ['POST'])]
    public function removePassword(Request $request, string $service): Response
    {
        $service = $this->service($service);
        $this->assertCsrf($request, $service);

        $this->passwords->remove($this->currentUser(), $service);
        $this->entityManager->flush();
        $this->addFlash('success', 'externalServicePasswordRemovedFlashMessage');

        return $this->backToProfile($service);
    }

    private function service(string $value): ExternalService
    {
        $service = ExternalService::tryFrom($value);

        if (null === $service || !$this->featureAccess->isEnabled($service->feature(), $this->currentUser())) {
            throw $this->createNotFoundException();
        }

        return $service;
    }

    private function assertCsrf(Request $request, ExternalService $service): void
    {
        if (!$this->isCsrfTokenValid('external_service_password_'.$service->value, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function backToProfile(ExternalService $service): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl('app_profile').'#external-service-'.$service->value);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }
}
