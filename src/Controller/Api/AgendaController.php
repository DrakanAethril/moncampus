<?php

namespace App\Controller\Api;

use App\Entity\AgendaEvent;
use App\Entity\SignupList;
use App\Entity\User;
use App\Repository\AgendaEventRepository;
use App\Repository\SignupListRegistrationRepository;
use App\Security\Voter\AudienceTargetableVoter;
use App\Security\Voter\SignupListVoter;
use App\Service\AudienceLabelFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only mobile counterpart to AgendaController's list() action - same
 * findUpcoming()/findPast() + AudienceTargetableVoter::VIEW filtering, just plain JSON instead of
 * Twig. Also surfaces each event's attached SignupList (if any - see AgendaEvent::$signupList's
 * docblock) with enough state (registrationCount/isRegistered/canRegister/canUnregister) for a
 * mobile client to render a register/unregister button without a second request; the actual
 * register/unregister actions themselves live in Api\SignupListController, not here.
 */
class AgendaController extends AbstractController
{
    #[Route(path: '/api/agenda', name: 'api_agenda', methods: ['GET'])]
    public function list(Request $request, AgendaEventRepository $repository, SignupListRegistrationRepository $registrationRepository, AudienceLabelFormatter $labelFormatter): JsonResponse
    {
        $showPast = $request->query->getBoolean('past');
        $events = $showPast ? $repository->findPast() : $repository->findUpcoming();

        $visibleEvents = array_values(array_filter(
            $events,
            fn (AgendaEvent $event): bool => $this->isGranted(AudienceTargetableVoter::VIEW, $event),
        ));

        return $this->json([
            'events' => array_map(fn (AgendaEvent $event): array => $this->formatEvent($event, $registrationRepository, $labelFormatter), $visibleEvents),
        ]);
    }

    /** @return array{id: int, title: string, description: string|null, startAt: string, endAt: string|null, location: string|null, audienceLabel: string, signupList: array|null} */
    private function formatEvent(AgendaEvent $event, SignupListRegistrationRepository $registrationRepository, AudienceLabelFormatter $labelFormatter): array
    {
        return [
            'id' => $event->getId(),
            'title' => $event->getTitle(),
            'description' => $event->getDescription(),
            'startAt' => $event->getStartAt()->format(\DateTimeInterface::ATOM),
            'endAt' => $event->getEndAt()?->format(\DateTimeInterface::ATOM),
            'location' => $event->getLocation(),
            'audienceLabel' => $labelFormatter->format($event),
            'signupList' => null !== $event->getSignupList() ? $this->formatSignupList($event->getSignupList(), $registrationRepository) : null,
        ];
    }

    /** @return array{id: int, title: string, registrationCount: int, registrationOpen: bool, isRegistered: bool, canRegister: bool, canUnregister: bool} */
    private function formatSignupList(SignupList $signupList, SignupListRegistrationRepository $registrationRepository): array
    {
        $user = $this->currentUser();

        return [
            'id' => $signupList->getId(),
            'title' => $signupList->getTitle(),
            'registrationCount' => $registrationRepository->countForSignupList($signupList),
            'registrationOpen' => $signupList->isRegistrationOpen(),
            'isRegistered' => null !== $registrationRepository->findOneForSignupListAndUser($signupList, $user),
            'canRegister' => $this->isGranted(SignupListVoter::REGISTER, $signupList),
            'canUnregister' => $this->isGranted(SignupListVoter::UNREGISTER, $signupList),
        ];
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
