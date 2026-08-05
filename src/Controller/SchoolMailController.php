<?php

namespace App\Controller;

use App\Entity\EmailMessage;
use App\Entity\JobApplication;
use App\Entity\User;
use App\Enum\EmailDirection;
use App\Repository\EmailMessageRepository;
use App\Repository\JobApplicationRepository;
use App\Repository\JobSearchRepository;
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

    /**
     * Opening a mail. The folder shown follows the message's direction rather than a parameter:
     * landing on a sent mail from "View mails" must open Sent, not the inbox.
     */
    #[Route(path: '/school-mail/mail/{id}', name: 'app_school_mail_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, EmailMessage $message): Response
    {
        /** @var User $student */
        $student = $this->getUser();

        if ($message->getStudent()?->getId() !== $student->getId()) {
            throw $this->createAccessDeniedException();
        }

        if ($message->isUnread()) {
            $message->setReadAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        return $this->renderFolder($request, $message->getDirection(), $message);
    }

    private function renderFolder(Request $request, EmailDirection $direction, ?EmailMessage $selected = null): Response
    {
        /** @var User $student */
        $student = $this->getUser();

        $search = trim((string) $request->query->get('q', ''));
        $application = $this->resolveApplication($request, $student);

        $messages = $this->messageRepository->findFolderForStudent($student, $direction, $application, '' === $search ? null : $search);

        return $this->render('school_mail/index.html.twig', [
            'folder' => EmailDirection::Inbound === $direction ? 'inbox' : 'sent',
            'messages' => $messages,
            'selected' => $selected,
            'search' => $search,
            'application' => $application,
            'counts' => [
                'unread' => $this->messageRepository->countUnreadForStudent($student),
                'inbox' => $this->messageRepository->countFolderForStudent($student, EmailDirection::Inbound),
                'sent' => $this->messageRepository->countFolderForStudent($student, EmailDirection::Outbound),
            ],
            'contexts' => $this->contexts($student),
            // The mockup's permanent identity banner: "You are writing as ...".

            'mailbox' => $this->mailboxResolver->addressFor($student),
            'remainingQuota' => $this->sender->remainingQuota($student),
            // A closed job search leaves the mailbox readable but turns sending off (screen 1a).
            'searchClosed' => $this->searchRepository->isClosedFor($student),
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
