<?php

namespace App\Controller\Api;

use App\Entity\SignupList;
use App\Entity\SignupListRegistration;
use App\Entity\User;
use App\Repository\SignupListRegistrationRepository;
use App\Repository\SignupListRepository;
use App\Security\Voter\SignupListVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mobile counterpart to SignupListController::register()/unregister() - same SignupListVoter
 * checks and SignupListRegistration persistence, just JSON instead of a redirect+flash, and no
 * CSRF token (the `api` firewall is stateless/JWT-bearer-authenticated, not cookie-based, so CSRF
 * doesn't apply the way it does on the web session - see config/packages/security.yaml). The only
 * mobile-facing piece of the sign-up list feature: browsing a list's own page/roster/CSV export
 * isn't exposed here, only what Api\AgendaController's event feed needs to make its
 * register/unregister button work.
 */
class SignupListController extends AbstractController
{
    #[Route(path: '/api/signup-lists/{id}/register', name: 'api_signup_lists_register', methods: ['POST'])]
    public function register(int $id, SignupListRepository $repository, SignupListRegistrationRepository $registrationRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $signupList = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SignupListVoter::REGISTER, $signupList);

        if (!$signupList->isRegistrationOpen()) {
            return $this->json(['error' => 'registration_closed'], 422);
        }

        if (null === $registrationRepository->findOneForSignupListAndUser($signupList, $this->currentUser())) {
            $entityManager->persist(new SignupListRegistration($signupList, $this->currentUser()));
            $entityManager->flush();
        }

        return $this->json($this->formatSignupList($signupList, $registrationRepository));
    }

    #[Route(path: '/api/signup-lists/{id}/unregister', name: 'api_signup_lists_unregister', methods: ['POST'])]
    public function unregister(int $id, SignupListRepository $repository, SignupListRegistrationRepository $registrationRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $signupList = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SignupListVoter::UNREGISTER, $signupList);

        $registration = $registrationRepository->findOneForSignupListAndUser($signupList, $this->currentUser()) ?? throw $this->createNotFoundException();
        $entityManager->remove($registration);
        $entityManager->flush();

        return $this->json($this->formatSignupList($signupList, $registrationRepository));
    }

    /** @return array{id: int, registrationCount: int, registrationOpen: bool, isRegistered: bool, canRegister: bool, canUnregister: bool} */
    private function formatSignupList(SignupList $signupList, SignupListRegistrationRepository $registrationRepository): array
    {
        return [
            'id' => $signupList->getId(),
            'registrationCount' => $registrationRepository->countForSignupList($signupList),
            'registrationOpen' => $signupList->isRegistrationOpen(),
            'isRegistered' => null !== $registrationRepository->findOneForSignupListAndUser($signupList, $this->currentUser()),
            'canRegister' => $this->isGranted(SignupListVoter::REGISTER, $signupList),
            'canUnregister' => $this->isGranted(SignupListVoter::UNREGISTER, $signupList),
        ];
    }

    private function findOrNotFound(SignupListRepository $repository, int $id): SignupList
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
