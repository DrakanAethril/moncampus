<?php

namespace App\Controller;

use App\Entity\EmailMessage;
use App\Entity\SchoolMailDraft;
use App\Entity\User;
use App\Repository\EmailMessageRepository;
use App\Repository\JobSearchRepository;
use App\Repository\SchoolMailDraftRepository;
use App\Repository\SuppressedEmailAddressRepository;
use App\Service\JobApplicationResolver;
use App\Service\SchoolMailLockChecker;
use App\Service\SchoolMailSender;
use App\Service\StudentMailboxResolver;
use App\Service\StudentSignatureBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "New company mail" - composing a message (design_handoff_stage_alternance, screen 3d), naming the
 * démarche included (screen 3g, which lives in this very form).
 *
 * Naming is **blocking** (principle #4): nothing leaves until the mail belongs to a démarche. The
 * suggestion made as the "To" field is typed is there for comfort; the démarche is settled again
 * here at send time, from the name actually submitted - what the browser prefilled is never taken
 * at face value.
 *
 * No template picker and no scheduled sending (principle #2), and attachments are free
 * (principle #9): the student attaches whatever they want, not only approved documents.
 */
#[IsGranted('ROLE_STUDENT')]
class SchoolMailComposeController extends AbstractController
{
    public function __construct(
        private readonly JobApplicationResolver $resolver,
        private readonly SchoolMailSender $sender,
        private readonly StudentMailboxResolver $mailboxResolver,
        private readonly StudentSignatureBuilder $signatureBuilder,
        private readonly JobSearchRepository $searchRepository,
        private readonly SchoolMailLockChecker $lockChecker,
        private readonly EmailMessageRepository $messageRepository,
        private readonly SchoolMailDraftRepository $draftRepository,
        private readonly SuppressedEmailAddressRepository $suppressionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAIL_STUDENT_DOMAIN)%')]
        private readonly string $studentMailDomain,
    ) {
    }

    #[Route(path: '/school-mail/compose', name: 'app_school_mail_compose', methods: ['GET'])]
    public function compose(Request $request): Response
    {
        /** @var User $student */
        $student = $this->getUser();
        $draft = $this->resolveDraft($request, $student);
        $reply = $draft?->getReplyTo() ?? $this->resolveReply($request, $student);

        // A reply stays in the démarche of the mail it answers: the student is not asked again what
        // they already said when they wrote first.
        $application = $reply?->getJobApplication()?->getName() ?? '';

        if (null !== $draft) {
            return $this->renderCompose($student, [
                'to' => (string) $draft->getRecipient(),
                'subject' => (string) $draft->getSubject(),
                'body' => (string) $draft->getBody(),
                'application' => $application,
            ], $reply, null, Response::HTTP_OK, $draft);
        }

        return $this->renderCompose($student, [
            'to' => $reply?->getFromAddress() ?? (string) $request->query->get('to', ''),
            'subject' => null !== $reply ? $this->replySubject($reply) : '',
            'body' => '',
            'application' => $application,
        ], $reply);
    }

    /**
     * Autosave of the draft being written (screen 3d's "Draft saved"). Called by the browser rather
     * than by a form submit, so it answers JSON and returns the draft id the next save will reuse.
     *
     * A draft emptied of everything is deleted rather than kept: an empty line in the Drafts folder
     * is noise, not work in progress.
     */
    #[Route(path: '/school-mail/draft', name: 'app_school_mail_draft_save', methods: ['POST'])]
    public function saveDraft(Request $request): JsonResponse
    {
        /** @var User $student */
        $student = $this->getUser();

        if (!$this->isCsrfTokenValid('school_mail_draft', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $draft = $this->resolveDraft($request, $student) ?? (new SchoolMailDraft())->setStudent($student);
        $draft
            ->setRecipient(trim((string) $request->request->get('to', '')) ?: null)
            ->setSubject(trim((string) $request->request->get('subject', '')) ?: null)
            ->setBody((string) $request->request->get('body', '') ?: null)
            ->setReplyTo($this->resolveReply($request, $student))
            ->touch();

        if ($draft->isEmpty()) {
            if (null !== $draft->getId()) {
                $this->entityManager->remove($draft);
                $this->entityManager->flush();
            }

            return $this->json(['draft' => null]);
        }

        if (null === $draft->getId()) {
            $this->entityManager->persist($draft);
        }

        $this->entityManager->flush();

        return $this->json(['draft' => $draft->getId()]);
    }

    #[Route(path: '/school-mail/draft/{id}/delete', name: 'app_school_mail_draft_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteDraft(Request $request, SchoolMailDraft $draft): Response
    {
        /** @var User $student */
        $student = $this->getUser();

        if ($draft->getStudent()?->getId() !== $student->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('school_mail_draft_delete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // A real delete, unlike a mail's: a draft never left, so there is nothing to keep a trace of.
        $this->entityManager->remove($draft);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_school_mail_drafts');
    }

    /**
     * The suggestion run as the "To" field is typed: the démarche this student already used with
     * that address, if there is one. Read-only and free of side effects - it names a démarche, it
     * creates nothing.
     */
    #[Route(path: '/school-mail/recipient-check', name: 'app_school_mail_recipient_check', methods: ['GET'])]
    public function recipientCheck(Request $request): JsonResponse
    {
        /** @var User $student */
        $student = $this->getUser();
        $address = trim((string) $request->query->get('address', ''));

        if (!filter_var($address, \FILTER_VALIDATE_EMAIL)) {
            return $this->json(['application' => null]);
        }

        return $this->json(['application' => $this->resolver->suggest($address, $student)?->getName()]);
    }

    #[Route(path: '/school-mail/send', name: 'app_school_mail_send', methods: ['POST'])]
    public function send(Request $request): Response
    {
        /** @var User $student */
        $student = $this->getUser();

        if (!$this->isCsrfTokenValid('school_mail_send', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $values = [
            'to' => trim((string) $request->request->get('to', '')),
            'subject' => trim((string) $request->request->get('subject', '')),
            'body' => (string) $request->request->get('body', ''),
            'application' => trim((string) $request->request->get('application', '')),
        ];
        $reply = $this->resolveReply($request, $student);

        $error = $this->validate($student, $values);

        if (null !== $error) {
            // Turbo only renders a form response when it isn't a 200: 422 is the status meant for
            // "here is your input back, to be corrected".
            return $this->renderCompose($student, $values, $reply, $error, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $message = $this->sender->send(
                $student,
                $this->resolver->applicationFor($student, $values['application']),
                (string) $this->mailboxResolver->addressFor($student),
                $values['to'],
                $values['subject'],
                $values['body'],
                array_filter($request->files->all()['attachments'] ?? []),
                $reply,
            );
        } catch (TransportExceptionInterface $exception) {
            // SES refused the mail - an unverified recipient while the account is still in the
            // sandbox, a throttle, a bad identity. Nothing was written, since the row is only
            // created once the mail is out; the student gets their text back and a reason.
            $this->logger->error('School mail: SES refused an outgoing mail.', [
                'student' => $student->getUsername(),
                'to' => $values['to'],
                'error' => $exception->getMessage(),
            ]);

            return $this->renderCompose($student, $values, $reply, 'schoolMailTransportError', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The draft has become a mail: keeping it would offer to write again what was just sent.
        $draft = $this->resolveDraft($request, $student);

        if (null !== $draft) {
            $this->entityManager->remove($draft);
            $this->entityManager->flush();
        }

        $this->addFlash('success', 'schoolMailSentFlash');

        return $this->redirectToRoute('app_school_mail_show', ['id' => $message->getId()]);
    }

    /**
     * @param array{to: string, subject: string, body: string, application: string} $values
     */
    private function validate(User $student, array $values): ?string
    {
        // A closed job search turns sending off and leaves the mailbox readable (screen 1a): the
        // one state where a student keeps their mails without being able to write any.
        if ($this->searchRepository->isClosedFor($student)) {
            return 'schoolMailSearchClosedError';
        }

        // Nothing reaches a real company before a teacher has read what this student would send
        // (design_handoff_workflow_postulation): the practice application is the door, and it is
        // shut until its four elements are validated.
        if ($this->lockChecker->isLocked($student)) {
            return 'schoolMailLockedError';
        }

        if (null === $this->mailboxResolver->addressFor($student)) {
            return 'schoolMailNoMailboxError';
        }

        if (!filter_var($values['to'], \FILTER_VALIDATE_EMAIL)) {
            return 'schoolMailInvalidRecipientError';
        }

        // Principle #8: School mail's "To" only accepts external addresses. Writing to a classmate
        // happens in Messaging, and the two mailboxes never talk to each other.
        if (str_ends_with(mb_strtolower($values['to']), '@'.mb_strtolower($this->studentMailDomain))) {
            return 'schoolMailInternalRecipientError';
        }

        // An address SES reported as dead, or whose owner marked us as spam (infra handoff §6).
        // Writing again costs the whole school's sending reputation, so the block is here rather
        // than left to SES to enforce silently.
        if ($this->suppressionRepository->isSuppressed($values['to'])) {
            return 'schoolMailSuppressedRecipientError';
        }

        // Principle #4, in its new form: a mail always belongs to a démarche, which is what lets the
        // reply find its way back through In-Reply-To. The name is the student's own wording, so
        // there is nothing to validate beyond its presence and its length.
        if ('' === $values['application']) {
            return 'schoolMailApplicationRequiredError';
        }

        if (mb_strlen($values['application']) > 255) {
            return 'schoolMailApplicationTooLongError';
        }

        if ('' === $values['subject']) {
            return 'schoolMailMissingSubjectError';
        }

        if ('' === trim($values['body'])) {
            return 'schoolMailMissingBodyError';
        }

        return null;
    }

    /**
     * @param array{to: string, subject: string, body: string, application: string} $values
     */
    private function renderCompose(
        User $student,
        array $values,
        ?EmailMessage $reply = null,
        ?string $error = null,
        int $status = Response::HTTP_OK,
        ?SchoolMailDraft $draft = null,
    ): Response {
        $mailbox = $this->mailboxResolver->addressFor($student);

        return $this->render('school_mail/compose.html.twig', [
            'values' => $values,
            'reply' => $reply,
            'draft' => $draft,
            'error' => $error,
            'mailbox' => $mailbox,
            'signature' => $this->signatureBuilder->build($student, $mailbox),
            'searchClosed' => $this->searchRepository->isClosedFor($student),
            // The démarches already opened in this class, offered as a list: naming one again is how
            // a follow-up joins the mails it belongs with.
            'applicationNames' => $this->resolver->namesFor($student),
        ], new Response(status: $status));
    }

    /** The draft being resumed or autosaved, provided it belongs to the signed-in student. */
    private function resolveDraft(Request $request, User $student): ?SchoolMailDraft
    {
        $id = $request->query->getInt('draft') ?: (int) $request->request->get('draft');

        if ($id <= 0) {
            return null;
        }

        $draft = $this->draftRepository->find($id);

        return null !== $draft && $draft->getStudent()?->getId() === $student->getId() ? $draft : null;
    }

    /** The mail being replied to, provided it belongs to the signed-in student. */
    private function resolveReply(Request $request, User $student): ?EmailMessage
    {
        $id = $request->query->getInt('reply') ?: (int) $request->request->get('reply');

        if ($id <= 0) {
            return null;
        }

        $message = $this->messageRepository->find($id);

        return null !== $message && $message->getStudent()?->getId() === $student->getId() ? $message : null;
    }

    private function replySubject(EmailMessage $message): string
    {
        $subject = (string) $message->getSubject();

        return str_starts_with(mb_strtolower($subject), 're:') ? $subject : 'RE: '.$subject;
    }
}
