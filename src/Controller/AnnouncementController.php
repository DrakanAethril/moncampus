<?php

namespace App\Controller;

use App\Entity\Announcement;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Form\AnnouncementType;
use App\Repository\AnnouncementRepository;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AudienceTargetableVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Institutional broadcasts (circulaires) - see App\Entity\Announcement's docblock for why this is
// a standalone entity rather than riding on the messaging system.
#[IsGranted('ROLE_USER')]
class AnnouncementController extends AbstractController
{
    private const string ROLE_EXTERNAL = 'ROLE_EXTERNAL';
    private const string MANAGE_ACCESS_EXPRESSION = 'is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")';

    #[Route(path: '/announcements', name: 'app_announcements')]
    public function list(AnnouncementRepository $repository): Response
    {
        $canManage = $this->isGranted(new Expression(self::MANAGE_ACCESS_EXPRESSION));

        // Staff managing announcements see every one they've published (including expired, to
        // reference or reactivate); everyone else only sees active ones actually addressed to
        // them - never a management view of announcements outside their own audience.
        $announcements = $canManage
            ? $repository->findAllOrderedByDate()
            : array_values(array_filter(
                $repository->findAllActive(),
                fn (Announcement $announcement): bool => $this->isGranted(AudienceTargetableVoter::VIEW, $announcement),
            ));

        return $this->render('announcement/index.html.twig', [
            'announcements' => $announcements,
            'canManage' => $canManage,
        ]);
    }

    // A distinct literal segment ("new"/"{id}/edit", not a bare "{id}") - there's no
    // App\Controller\AnnouncementController::show() to conflict with (announcements have no
    // detail page, the list already shows the full body), but kept consistent with every other
    // controller in this app for the same reason.
    #[IsGranted(new Expression(self::MANAGE_ACCESS_EXPRESSION))]
    #[Route(path: '/announcements/new', name: 'app_announcements_new')]
    #[Route(path: '/announcements/{id}/edit', name: 'app_announcements_edit')]
    public function form(Request $request, EntityManagerInterface $entityManager, AnnouncementRepository $repository, ProgramRepository $programRepository, UserRepository $userRepository, #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer, ?int $id = null): Response
    {
        $announcement = null !== $id ? $this->findOrNotFound($repository, $id) : new Announcement();
        $isEdit = null !== $id;

        $form = $this->createForm(AnnouncementType::class, $announcement, [
            'programs' => $programRepository->findActiveForNav(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $announcement->setBody($sanitizer->sanitize($announcement->getBody()));
            $this->applyManualRecipients($announcement, $request, $userRepository);

            if (!$isEdit) {
                $announcement->setCreatedBy($this->currentUser());
                $entityManager->persist($announcement);
            } else {
                $announcement->setLastUpdatedBy($this->currentUser());
                $announcement->setLastUpdatedDate(new \DateTimeImmutable());
            }

            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'announcementUpdatedFlashMessage' : 'announcementCreatedFlashMessage');

            return $this->redirectToRoute('app_announcements');
        }

        return $this->render('announcement/announcement_new.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'announcement' => $announcement,
        ]);
    }

    // Backs the tom-select ajax widget for manual recipients (see AnnouncementType's class
    // docblock) - unlike App\Controller\MessageController's equivalent, not filtered through
    // MessagingAccessChecker's permission matrix: only staff/admin ever reach this (class-level
    // Expression above), and an announcement isn't scoped by the composer's own reachability the
    // way a 1:1 message is, so any active non-external user is a valid candidate.
    #[IsGranted(new Expression(self::MANAGE_ACCESS_EXPRESSION))]
    #[Route(path: '/announcements/recipients-search', name: 'app_announcements_recipients_search')]
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
    #[Route(path: '/announcements/{id}/deactivate', name: 'app_announcements_deactivate', methods: ['POST'])]
    public function deactivate(int $id, Request $request, EntityManagerInterface $entityManager, AnnouncementRepository $repository): Response
    {
        $announcement = $this->findOrNotFound($repository, $id);

        if (!$this->isCsrfTokenValid('announcement_deactivate', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $announcement->setExpiresAt(new \DateTimeImmutable());
        $entityManager->flush();

        $this->addFlash('success', 'announcementDeactivatedFlashMessage');

        return $this->redirectToRoute('app_announcements');
    }

    private function applyManualRecipients(Announcement $announcement, Request $request, UserRepository $userRepository): void
    {
        foreach ($announcement->getManualRecipients()->toArray() as $recipient) {
            $announcement->removeManualRecipient($recipient);
        }

        if (MessageAudienceType::Manual !== $announcement->getAudienceType()) {
            return;
        }

        $ids = array_map(intval(...), $request->request->all('recipients'));
        foreach ($userRepository->findByIds($ids) as $recipient) {
            $announcement->addManualRecipient($recipient);
        }
    }

    private function findOrNotFound(AnnouncementRepository $repository, int $id): Announcement
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
