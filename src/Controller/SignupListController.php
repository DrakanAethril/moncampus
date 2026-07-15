<?php

namespace App\Controller;

use App\Entity\AgendaEvent;
use App\Entity\Announcement;
use App\Entity\MessageThread;
use App\Entity\SignupList;
use App\Entity\SignupListAttachment;
use App\Entity\SignupListRegistration;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Form\SignupListType;
use App\Repository\AgendaEventRepository;
use App\Repository\AnnouncementRepository;
use App\Repository\MessageThreadRepository;
use App\Repository\SignupListRegistrationRepository;
use App\Repository\SignupListRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AudienceTargetableVoter;
use App\Security\Voter\SignupListVoter;
use App\Service\AudienceResolver;
use App\Service\FileUploadService;
use App\Service\SignupListAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Sign-up lists ("listes d'inscription") - see design proposal discussed 2026-07-15. A list is
// audience-targeted the same way Announcement/AgendaEvent/MessageThread are (SignupList implements
// AudienceTargetable) and can optionally be attached to one of those three (a nullable signupList
// FK on THAT side, not here - see AgendaEvent::$signupList's docblock).
#[IsGranted('ROLE_USER')]
class SignupListController extends AbstractController
{
    private const string ATTACHMENT_PREFIX = 'signup-lists/';

    #[Route(path: '/signup-lists', name: 'app_signup_lists')]
    public function index(SignupListRepository $repository, SignupListAccessChecker $accessChecker): Response
    {
        $canManageAny = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF') || $this->isGranted('ROLE_STAFF-LEAD');

        // Staff see every list (to manage any of them); everyone else only sees ones they created
        // or are actually in the audience of - never a management view of lists outside their own
        // reach. Same "fetch unfiltered, narrow via Voter" convention as AnnouncementController::
        // list()/AgendaController::list().
        $signupLists = $canManageAny
            ? $repository->findAllOrderedByDate()
            : array_values(array_filter(
                $repository->findAllOrderedByDate(),
                fn (SignupList $signupList): bool => $this->isGranted(SignupListVoter::MANAGE, $signupList) || $this->isGranted(AudienceTargetableVoter::VIEW, $signupList),
            ));

        return $this->render('signup_list/index.html.twig', [
            'signupLists' => $signupLists,
            'canCreate' => $accessChecker->canCreate($this->currentUser()),
        ]);
    }

    #[Route(path: '/signup-lists/new', name: 'app_signup_lists_new')]
    #[Route(path: '/signup-lists/{id}/edit', name: 'app_signup_lists_edit')]
    public function form(
        Request $request,
        EntityManagerInterface $entityManager,
        SignupListRepository $repository,
        SignupListAccessChecker $accessChecker,
        UserRepository $userRepository,
        FileUploadService $fileUploadService,
        #[Target('app.signup_list_description')] HtmlSanitizerInterface $sanitizer,
        ?int $id = null,
    ): Response {
        $sender = $this->currentUser();
        $isEdit = null !== $id;

        if ($isEdit) {
            $signupList = $this->findOrNotFound($repository, $id);
            $this->denyAccessUnlessGranted(SignupListVoter::MANAGE, $signupList);
        } else {
            if (!$accessChecker->canCreate($sender)) {
                throw $this->createAccessDeniedException();
            }
            $signupList = new SignupList();
        }

        $allowedAudienceTypes = $accessChecker->allowedAudienceTypes($sender);
        $allowedPrograms = $accessChecker->programsForAudienceShortcut($sender);

        $form = $this->createForm(SignupListType::class, $signupList, [
            'allowedAudienceTypes' => $allowedAudienceTypes,
            'programs' => $allowedPrograms,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!\in_array($signupList->getAudienceType(), $allowedAudienceTypes, true)) {
                throw $this->createAccessDeniedException();
            }

            $this->applyManualRecipients($signupList, $request, $accessChecker, $sender);

            if (MessageAudienceType::Program === $signupList->getAudienceType()) {
                foreach ($signupList->getPrograms() as $program) {
                    if (!\in_array($program, $allowedPrograms, true)) {
                        throw $this->createAccessDeniedException();
                    }
                }
            } else {
                foreach ($signupList->getPrograms()->toArray() as $program) {
                    $signupList->removeProgram($program);
                }
            }

            $signupList->setDescription($sanitizer->sanitize($signupList->getDescription()));

            if (!$isEdit) {
                $signupList->setCreatedBy($sender);
                $entityManager->persist($signupList);
            } else {
                $signupList->setLastUpdatedBy($sender);
                $signupList->setLastUpdatedDate(new \DateTimeImmutable());
            }

            $this->persistAttachments($signupList, $form->get('attachments')->getData(), $fileUploadService, $entityManager);

            $entityManager->flush();

            $this->addFlash('success', $isEdit ? 'signupListUpdatedFlashMessage' : 'signupListCreatedFlashMessage');

            return $this->redirectToRoute('app_signup_lists_show', ['id' => $signupList->getId()]);
        }

        return $this->render('signup_list/form.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'signupList' => $signupList,
        ]);
    }

    // Backs the tom-select ajax widget for Manual-audience recipients (see SignupListType's class
    // docblock) - only reachable by whoever could reach the "new"/"edit" form in the first place.
    // Declared before show()'s "/signup-lists/{id}" route: Symfony's router matches in
    // declaration order, and a literal path segment ("recipients-search") must be registered
    // ahead of a generic "{id}" pattern or the latter swallows it - same ordering MessageController
    // uses for its own recipients-search vs. show().
    #[Route(path: '/signup-lists/recipients-search', name: 'app_signup_lists_recipients_search')]
    public function recipientsSearch(Request $request, SignupListAccessChecker $accessChecker): JsonResponse
    {
        $sender = $this->currentUser();
        if (!$accessChecker->canCreate($sender)) {
            throw $this->createAccessDeniedException();
        }

        $limit = 20;
        $candidates = $accessChecker->searchManualCandidates($sender, $request->query->get('q'), $limit);

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], $candidates),
            'pagination' => ['more' => \count($candidates) === $limit],
        ]);
    }

    #[Route(path: '/signup-lists/{id}', name: 'app_signup_lists_show')]
    public function show(int $id, SignupListRepository $repository, SignupListRegistrationRepository $registrationRepository, AgendaEventRepository $agendaEventRepository, AnnouncementRepository $announcementRepository, MessageThreadRepository $messageThreadRepository): Response
    {
        $signupList = $this->findOrNotFound($repository, $id);

        $canManage = $this->isGranted(SignupListVoter::MANAGE, $signupList);
        if (!$canManage && !$this->isGranted(AudienceTargetableVoter::VIEW, $signupList)) {
            throw $this->createNotFoundException();
        }

        $canViewRoster = $this->isGranted(SignupListVoter::VIEW_ROSTER, $signupList);

        $parent = $this->resolveParent($signupList, $agendaEventRepository, $announcementRepository, $messageThreadRepository);

        return $this->render('signup_list/show.html.twig', [
            'signupList' => $signupList,
            'canManage' => $canManage,
            'canRegister' => $this->isGranted(SignupListVoter::REGISTER, $signupList),
            'canUnregister' => $this->isGranted(SignupListVoter::UNREGISTER, $signupList),
            'canViewRoster' => $canViewRoster,
            'registrationCount' => $registrationRepository->countForSignupList($signupList),
            'registrations' => $canViewRoster ? $registrationRepository->findAllForSignupList($signupList) : [],
            'parent' => $parent,
            // Twig has no clean instanceof check, so the discriminator is resolved here rather
            // than duck-typing on which getter happens to exist in the template.
            'parentType' => match (true) {
                $parent instanceof AgendaEvent => 'agenda_event',
                $parent instanceof Announcement => 'announcement',
                $parent instanceof MessageThread => 'message_thread',
                default => null,
            },
        ]);
    }

    #[Route(path: '/signup-lists/{id}/register', name: 'app_signup_lists_register', methods: ['POST'])]
    public function register(int $id, Request $request, SignupListRepository $repository, SignupListRegistrationRepository $registrationRepository, EntityManagerInterface $entityManager): Response
    {
        $signupList = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SignupListVoter::REGISTER, $signupList);
        $this->assertValidToken('signup_list_register', $request);

        $user = $this->currentUser();

        if (!$signupList->isRegistrationOpen()) {
            $this->addFlash('error', 'signupListRegistrationClosedFlashMessage');

            return $this->redirectToRoute('app_signup_lists_show', ['id' => $signupList->getId()]);
        }

        if (null === $registrationRepository->findOneForSignupListAndUser($signupList, $user)) {
            $entityManager->persist(new SignupListRegistration($signupList, $user));
            $entityManager->flush();
            $this->addFlash('success', 'signupListRegisteredFlashMessage');
        }

        return $this->redirectToRoute('app_signup_lists_show', ['id' => $signupList->getId()]);
    }

    #[Route(path: '/signup-lists/{id}/unregister', name: 'app_signup_lists_unregister', methods: ['POST'])]
    public function unregister(int $id, Request $request, SignupListRepository $repository, SignupListRegistrationRepository $registrationRepository, EntityManagerInterface $entityManager): Response
    {
        $signupList = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SignupListVoter::UNREGISTER, $signupList);
        $this->assertValidToken('signup_list_unregister', $request);

        $registration = $registrationRepository->findOneForSignupListAndUser($signupList, $this->currentUser()) ?? throw $this->createNotFoundException();
        $entityManager->remove($registration);
        $entityManager->flush();

        $this->addFlash('success', 'signupListUnregisteredFlashMessage');

        return $this->redirectToRoute('app_signup_lists_show', ['id' => $signupList->getId()]);
    }

    #[Route(path: '/signup-lists/{id}/attachments/{attachmentId}/remove', name: 'app_signup_lists_attachments_remove', methods: ['POST'])]
    public function removeAttachment(int $id, int $attachmentId, Request $request, SignupListRepository $repository, EntityManagerInterface $entityManager, FileUploadService $fileUploadService): Response
    {
        $signupList = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SignupListVoter::MANAGE, $signupList);
        $this->assertValidToken('signup_list_remove_attachment', $request);

        $attachment = null;
        foreach ($signupList->getAttachments() as $candidate) {
            if ($candidate->getId() === $attachmentId) {
                $attachment = $candidate;
                break;
            }
        }
        $attachment ?? throw $this->createNotFoundException();

        $fileUploadService->delete($attachment->getStorageKey());
        $entityManager->remove($attachment);
        $entityManager->flush();

        $this->addFlash('success', 'signupListAttachmentRemovedFlashMessage');

        return $this->redirectToRoute('app_signup_lists_edit', ['id' => $signupList->getId()]);
    }

    #[Route(path: '/signup-lists/{id}/delete', name: 'app_signup_lists_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, SignupListRepository $repository, EntityManagerInterface $entityManager, FileUploadService $fileUploadService, AgendaEventRepository $agendaEventRepository, AnnouncementRepository $announcementRepository, MessageThreadRepository $messageThreadRepository): Response
    {
        $signupList = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SignupListVoter::MANAGE, $signupList);
        $this->assertValidToken('signup_list_delete', $request);

        // Detach from whichever parent references it, if any - the FK is ON DELETE SET NULL at
        // the DB level too, but doing it explicitly here keeps the in-memory unit of work
        // consistent and avoids a stale reference for the rest of this request.
        foreach ([$agendaEventRepository->findOneBy(['signupList' => $signupList]), $announcementRepository->findOneBy(['signupList' => $signupList]), $messageThreadRepository->findOneBy(['signupList' => $signupList])] as $parent) {
            $parent?->setSignupList(null);
        }

        foreach ($signupList->getAttachments() as $attachment) {
            $fileUploadService->delete($attachment->getStorageKey());
        }

        $entityManager->remove($signupList);
        $entityManager->flush();

        $this->addFlash('success', 'signupListDeletedFlashMessage');

        return $this->redirectToRoute('app_signup_lists');
    }

    #[Route(path: '/signup-lists/{id}/export', name: 'app_signup_lists_export')]
    public function export(int $id, SignupListRepository $repository, SignupListRegistrationRepository $registrationRepository): StreamedResponse
    {
        $signupList = $this->findOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SignupListVoter::VIEW_ROSTER, $signupList);

        $registrations = $registrationRepository->findAllForSignupList($signupList);

        $response = new StreamedResponse(function () use ($registrations): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Nom complet', 'Identifiant', 'Email', "Date d'inscription"], ';');

            foreach ($registrations as $registration) {
                $user = $registration->getUser();
                fputcsv($handle, [
                    $user->getDisplayName() ?? $user->getUsername(),
                    $user->getUsername(),
                    $user->getEmail() ?? '',
                    $registration->getRegisteredAt()->format('d/m/Y H:i'),
                ], ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            'attachment',
            \sprintf('inscrits-%s.csv', (new \DateTimeImmutable())->format('Y-m-d')),
        ));

        return $response;
    }

    private function applyManualRecipients(SignupList $signupList, Request $request, SignupListAccessChecker $accessChecker, User $sender): void
    {
        foreach ($signupList->getManualRecipients()->toArray() as $recipient) {
            $signupList->removeManualRecipient($recipient);
        }

        if (MessageAudienceType::Manual !== $signupList->getAudienceType()) {
            return;
        }

        $ids = array_map('intval', $request->request->all('recipients'));
        foreach ($accessChecker->resolveManualRecipients($sender, $ids) as $recipient) {
            $signupList->addManualRecipient($recipient);
        }
    }

    private function resolveParent(SignupList $signupList, AgendaEventRepository $agendaEventRepository, AnnouncementRepository $announcementRepository, MessageThreadRepository $messageThreadRepository): AgendaEvent|Announcement|MessageThread|null
    {
        return $agendaEventRepository->findOneBy(['signupList' => $signupList])
            ?? $announcementRepository->findOneBy(['signupList' => $signupList])
            ?? $messageThreadRepository->findOneBy(['signupList' => $signupList]);
    }

    /** @param list<UploadedFile>|null $files */
    private function persistAttachments(SignupList $signupList, ?array $files, FileUploadService $fileUploadService, EntityManagerInterface $entityManager): void
    {
        foreach ($files ?? [] as $file) {
            $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
            $key = $fileUploadService->upload(self::ATTACHMENT_PREFIX, \sprintf('%s.%s', bin2hex(random_bytes(16)), $extension), $file);
            $entityManager->persist(new SignupListAttachment($signupList, $key, $file->getClientOriginalName()));
        }
    }

    private function findOrNotFound(SignupListRepository $repository, int $id): SignupList
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
    }

    private function assertValidToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
