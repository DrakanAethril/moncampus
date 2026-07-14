<?php

namespace App\Controller;

use App\Entity\AgendaEvent;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Form\AgendaEventType;
use App\Repository\AgendaEventRepository;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AudienceTargetableVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Institution events, distinct from the per-Program timetable - see App\Entity\AgendaEvent's
// docblock.
#[IsGranted('ROLE_USER')]
class AgendaController extends AbstractController
{
    private const string ROLE_EXTERNAL = 'ROLE_EXTERNAL';
    private const string MANAGE_ACCESS_EXPRESSION = 'is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")';

    #[Route(path: '/agenda', name: 'app_agenda')]
    public function list(Request $request, AgendaEventRepository $repository): Response
    {
        $showPast = $request->query->getBoolean('past');
        $events = $showPast ? $repository->findPast() : $repository->findUpcoming();

        $visibleEvents = array_values(array_filter(
            $events,
            fn (AgendaEvent $event): bool => $this->isGranted(AudienceTargetableVoter::VIEW, $event),
        ));

        return $this->render('agenda/index.html.twig', [
            'events' => $visibleEvents,
            'showPast' => $showPast,
            'canManage' => $this->isGranted(new Expression(self::MANAGE_ACCESS_EXPRESSION)),
        ]);
    }

    #[IsGranted(new Expression(self::MANAGE_ACCESS_EXPRESSION))]
    #[Route(path: '/agenda/new', name: 'app_agenda_new')]
    #[Route(path: '/agenda/{id}/edit', name: 'app_agenda_edit')]
    public function form(Request $request, EntityManagerInterface $entityManager, AgendaEventRepository $repository, ProgramRepository $programRepository, UserRepository $userRepository, ?int $id = null): Response
    {
        $event = null !== $id ? $this->findOrNotFound($repository, $id) : new AgendaEvent();
        $isEdit = null !== $id;

        $form = $this->createForm(AgendaEventType::class, $event, [
            'programs' => $programRepository->findActiveForNav(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyManualRecipients($event, $request, $userRepository);

            if (!$isEdit) {
                $event->setCreatedBy($this->currentUser());
                $entityManager->persist($event);
            } else {
                $event->setLastUpdatedBy($this->currentUser());
                $event->setLastUpdatedDate(new \DateTimeImmutable());
            }

            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'agendaEventUpdatedFlashMessage' : 'agendaEventCreatedFlashMessage');

            return $this->redirectToRoute('app_agenda');
        }

        return $this->render('agenda/agenda_event_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'event' => $event,
        ]);
    }

    // Same reasoning as AnnouncementController::recipientsSearch() - staff-only, unrestricted by
    // MessagingAccessChecker's 1:1-messaging permission matrix.
    #[IsGranted(new Expression(self::MANAGE_ACCESS_EXPRESSION))]
    #[Route(path: '/agenda/recipients-search', name: 'app_agenda_recipients_search')]
    public function recipientsSearch(Request $request, UserRepository $userRepository): JsonResponse
    {
        $limit = 20;
        $candidates = $userRepository->findActiveExcludingRole(self::ROLE_EXTERNAL, [], $request->query->get('q'));

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], \array_slice($candidates, 0, $limit)),
            'pagination' => ['more' => \count($candidates) > $limit],
        ]);
    }

    #[IsGranted(new Expression(self::MANAGE_ACCESS_EXPRESSION))]
    #[Route(path: '/agenda/{id}/delete', name: 'app_agenda_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $entityManager, AgendaEventRepository $repository): Response
    {
        $event = $this->findOrNotFound($repository, $id);

        if (!$this->isCsrfTokenValid('agenda_event_delete', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($event);
        $entityManager->flush();

        $this->addFlash('success', 'agendaEventDeletedFlashMessage');

        return $this->redirectToRoute('app_agenda');
    }

    private function applyManualRecipients(AgendaEvent $event, Request $request, UserRepository $userRepository): void
    {
        foreach ($event->getManualRecipients()->toArray() as $recipient) {
            $event->removeManualRecipient($recipient);
        }

        if (MessageAudienceType::Manual !== $event->getAudienceType()) {
            return;
        }

        $ids = array_map(intval(...), $request->request->all('recipients'));
        foreach ($userRepository->findByIds($ids) as $recipient) {
            $event->addManualRecipient($recipient);
        }
    }

    private function findOrNotFound(AgendaEventRepository $repository, int $id): AgendaEvent
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
