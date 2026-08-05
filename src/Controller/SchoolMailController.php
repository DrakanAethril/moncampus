<?php

namespace App\Controller;

use App\Entity\EmailMessage;
use App\Entity\JobApplication;
use App\Entity\User;
use App\Enum\EmailDirection;
use App\Repository\EmailMessageRepository;
use App\Repository\JobApplicationRepository;
use App\Repository\JobSearchRepository;
use App\Repository\SchoolMailDraftRepository;
use App\Service\SchoolMailLockChecker;
use App\Service\SchoolMailSender;
use App\Service\StudentMailboxResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "School mail" - the student's external mailbox (design_handoff_stage_alternance, screen 3b).
 *
 * A three-pane mailbox, like internal Messaging whose styling it reuses. The two never talk to each
 * other (handoff principle #8): here you only see SES mails exchanged with outside addresses, there
 * only internal messages. Hence the permanent identity banner, which recalls the address the
 * student writes from.
 *
 * No sorting of replies (principle #1): the "Reply received" chip says a message arrived, never
 * what it contains. The only read state tracked is the student's own inside their mailbox - nothing
 * is known about what the company does with what we send.
 */
#[IsGranted('ROLE_STUDENT')]
class SchoolMailController extends AbstractController
{
    public function __construct(
        private readonly EmailMessageRepository $messageRepository,
        private readonly JobApplicationRepository $applicationRepository,
        private readonly JobSearchRepository $searchRepository,
        private readonly SchoolMailDraftRepository $draftRepository,
        private readonly SchoolMailLockChecker $lockChecker,
        private readonly StudentMailboxResolver $mailboxResolver,
        private readonly SchoolMailSender $sender,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/school-mail', name: 'app_school_mail', methods: ['GET'])]
    public function inbox(Request $request): Response
    {
        return $this->renderFolder($request, EmailDirection::Inbound);
    }

    #[Route(path: '/school-mail/sent', name: 'app_school_mail_sent', methods: ['GET'])]
    public function sent(Request $request): Response
    {
        return $this->renderFolder($request, EmailDirection::Outbound);
    }

    #[Route(path: '/school-mail/drafts', name: 'app_school_mail_drafts', methods: ['GET'])]
    public function drafts(Request $request): Response
    {
        return $this->renderFolder($request, null, null, 'drafts');
    }

    #[Route(path: '/school-mail/trash', name: 'app_school_mail_trash', methods: ['GET'])]
    public function trash(Request $request): Response
    {
        return $this->renderFolder($request, null, null, 'trash');
    }

    /**
     * Moving a mail to the Trash. A soft delete: the `.eml` stays on S3 and the teacher screens keep
     * seeing the mail - tidying one's own mailbox is not erasing what left for a company.
     */
    #[Route(path: '/school-mail/mail/{id}/delete', name: 'app_school_mail_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, EmailMessage $message): Response
    {
        $this->denyUnlessOwned($message);

        if (!$this->isCsrfTokenValid('school_mail_delete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $message->setDeletedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->redirectToRoute('app_school_mail_trash');
    }

    #[Route(path: '/school-mail/mail/{id}/restore', name: 'app_school_mail_restore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function restore(Request $request, EmailMessage $message): Response
    {
        $this->denyUnlessOwned($message);

        if (!$this->isCsrfTokenValid('school_mail_restore', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $message->setDeletedAt(null);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_school_mail_show', ['id' => $message->getId()]);
    }

    /**
     * Opening a mail. The folder shown follows the message's direction rather than a parameter:
     * landing on a sent mail from "View mails" must open Sent, not the inbox.
     */
    #[Route(path: '/school-mail/mail/{id}', name: 'app_school_mail_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, EmailMessage $message): Response
    {
        /** @var User $student */
        $student = $this->getUser();

        $this->denyUnlessOwned($message);

        if ($message->isUnread()) {
            $message->setReadAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        if ($message->isDeleted()) {
            return $this->renderFolder($request, null, $message, 'trash');
        }

        return $this->renderFolder($request, $message->getDirection(), $message);
    }

    private function denyUnlessOwned(EmailMessage $message): void
    {
        /** @var User $student */
        $student = $this->getUser();

        if ($message->getStudent()?->getId() !== $student->getId()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function renderFolder(
        Request $request,
        ?EmailDirection $direction,
        ?EmailMessage $selected = null,
        ?string $folder = null,
    ): Response {
        /** @var User $student */
        $student = $this->getUser();

        $search = trim((string) $request->query->get('q', ''));
        $searchOrNull = '' === $search ? null : $search;
        $application = $this->resolveApplication($request, $student);
        $folder ??= EmailDirection::Inbound === $direction ? 'inbox' : 'sent';

        $messages = match ($folder) {
            'trash' => $this->messageRepository->findTrashForStudent($student, $application, $searchOrNull),
            'drafts' => [],
            default => $this->messageRepository->findFolderForStudent($student, $direction, $application, $searchOrNull),
        };

        return $this->render('school_mail/index.html.twig', [
            'folder' => $folder,
            'messages' => $messages,
            'drafts' => 'drafts' === $folder ? $this->draftRepository->findForStudent($student) : [],
            'selected' => $selected,
            'search' => $search,
            'application' => $application,
            'counts' => [
                'unread' => $this->messageRepository->countUnreadForStudent($student),
                'inbox' => $this->messageRepository->countFolderForStudent($student, EmailDirection::Inbound),
                'sent' => $this->messageRepository->countFolderForStudent($student, EmailDirection::Outbound),
                'drafts' => $this->draftRepository->countForStudent($student),
                'trash' => $this->messageRepository->countTrashForStudent($student),
            ],
            'contexts' => $this->contexts($student),
            // The mockup's permanent identity banner: "You are writing as ...".
            'mailbox' => $this->mailboxResolver->addressFor($student),
            // A closed job search leaves the mailbox readable but turns sending off (screen 1a).
            'searchClosed' => $this->searchRepository->isClosedFor($student),
            // Same idea, different reason: the practice application has not been validated yet
            // (design_handoff_workflow_postulation, screen 8a).
            'mailboxLocked' => $this->lockChecker->isLocked($student),
        ]);
    }

    private function resolveApplication(Request $request, User $student): ?JobApplication
    {
        $id = $request->query->getInt('application');

        if ($id <= 0) {
            return null;
        }

        $application = $this->applicationRepository->find($id);

        // A filter on another student's application is worth no more than an unknown one.
        if (null === $application || $application->getStudent()?->getId() !== $student->getId()) {
            return null;
        }

        return $application;
    }

    /**
     * The "Context: applications" block: the student's applications and how many mails each holds,
     * ordered by last activity, like screen 2b's list.
     *
     * @return list<array{application: JobApplication, mailCount: int, accent: int}>
     */
    private function contexts(User $student): array
    {
        $counts = $this->messageRepository->countByApplicationForStudent($student);
        $contexts = [];

        foreach ($this->applicationRepository->findForStudent($student) as $application) {
            // An application without a single mail is no reading context: it has nothing to
            // filter. It stays visible on screen 2b, which lists applications, not mails.
            if (0 === ($counts[$application->getId()] ?? 0)) {
                continue;
            }

            $contexts[] = [
                'application' => $application,
                'mailCount' => $counts[$application->getId()] ?? 0,
                'accent' => $application->getEnterprise()->getId() % 5,
            ];
        }

        usort($contexts, static fn (array $left, array $right): int => ($right['application']->getLastActivityAt() <=> $left['application']->getLastActivityAt()));

        return $contexts;
    }
}
