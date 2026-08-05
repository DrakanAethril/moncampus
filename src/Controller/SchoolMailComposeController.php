<?php

namespace App\Controller;

use App\Entity\EmailMessage;
use App\Entity\Enterprise;
use App\Entity\SchoolMailDraft;
use App\Entity\User;
use App\Repository\EmailMessageRepository;
use App\Repository\JobSearchRepository;
use App\Repository\SchoolMailDraftRepository;
use App\Service\EnterpriseRecipientResolver;
use App\Service\SchoolMailSender;
use App\Service\StudentMailboxResolver;
use App\Service\StudentSignatureBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "New company mail" - composing a message (design_handoff_stage_alternance, screen 3d), company
 * linking included (screen 3g, which lives in the "To" field of this very form).
 *
 * Linking is **blocking** (principle #4): nothing leaves until the company is known. The
 * client-side check is there for comfort; the decision is taken again here at send time - a company
 * whispered by the browser is never taken at face value, it is re-resolved from the address
 * actually submitted.
 *
 * No template picker and no scheduled sending (principle #2), and attachments are free
 * (principle #9): the student attaches whatever they want, not only approved documents.
 */
#[IsGranted('ROLE_STUDENT')]
class SchoolMailComposeController extends AbstractController
{
    public function __construct(
        private readonly EnterpriseRecipientResolver $resolver,
        private readonly SchoolMailSender $sender,
        private readonly StudentMailboxResolver $mailboxResolver,
        private readonly StudentSignatureBuilder $signatureBuilder,
        private readonly JobSearchRepository $searchRepository,
        private readonly EmailMessageRepository $messageRepository,
        private readonly SchoolMailDraftRepository $draftRepository,
        private readonly EntityManagerInterface $entityManager,
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

        if (null !== $draft) {
            return $this->renderCompose($student, [
                'to' => (string) $draft->getRecipient(),
                'subject' => (string) $draft->getSubject(),
                'body' => (string) $draft->getBody(),
            ], $reply, null, Response::HTTP_OK, $draft);
        }

        return $this->renderCompose($student, [
            'to' => $reply?->getFromAddress() ?? (string) $request->query->get('to', ''),
            'subject' => null !== $reply ? $this->replySubject($reply) : '',
            'body' => '',
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
     * The linking check run as the "To" field is typed. Read-only and free of side effects: it says
     * which of the three cases applies, it creates nothing.
     */
    #[Route(path: '/school-mail/recipient-check', name: 'app_school_mail_recipient_check', methods: ['GET'])]
    public function recipientCheck(Request $request): JsonResponse
    {
        /** @var User $student */
        $student = $this->getUser();
        $address = trim((string) $request->query->get('address', ''));

        if (!filter_var($address, \FILTER_VALIDATE_EMAIL)) {
            return $this->json(['case' => 'invalid']);
        }

        $resolution = $this->resolver->resolve($address, $student);

        return $this->json([
            'case' => $resolution['case'],
            'domain' => $resolution['domain'],
            'generic' => $resolution['generic'],
            'enterpriseId' => $resolution['enterprise']?->getId(),
            'enterpriseName' => $resolution['enterprise']?->getName(),
        ]);
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
        ];
        $reply = $this->resolveReply($request, $student);

        $error = $this->validate($student, $values);

        if (null === $error) {
            $enterprise = $this->resolveEnterprise($request, $student, $values['to'], $error);
        }

        if (null !== $error) {
            // Turbo only renders a form response when it isn't a 200: 422 is the status meant for
            // "here is your input back, to be corrected".
            return $this->renderCompose($student, $values, $reply, $error, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $message = $this->sender->send(
            $student,
            $this->resolver->applicationFor($student, $enterprise),
            (string) $this->mailboxResolver->addressFor($student),
            $values['to'],
            $values['subject'],
            $values['body'],
            array_filter($request->files->all()['attachments'] ?? []),
            $reply,
        );

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
     * @param array{to: string, subject: string, body: string} $values
     */
    private function validate(User $student, array $values): ?string
    {
        // A closed job search turns sending off and leaves the mailbox readable (screen 1a): the
        // one state where a student keeps their mails without being able to write any.
        if ($this->searchRepository->isClosedFor($student)) {
            return 'schoolMailSearchClosedError';
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

        if ('' === $values['subject']) {
            return 'schoolMailMissingSubjectError';
        }

        if ('' === trim($values['body'])) {
            return 'schoolMailMissingBodyError';
        }

        return null;
    }

    /**
     * The server-side take on the linking decision. The case is recomputed from the submitted
     * address: the browser only ever proposes.
     */
    private function resolveEnterprise(Request $request, User $student, string $address, ?string &$error): ?Enterprise
    {
        $resolution = $this->resolver->resolve($address, $student);

        if (EnterpriseRecipientResolver::CASE_LINKED === $resolution['case']) {
            return $resolution['enterprise'];
        }

        // Cast rather than getInt(): the field is empty whenever no company is settled yet, and
        // InputBag::getInt() rejects an empty string outright.
        $confirmedId = (int) $request->request->get('enterprise');

        if (EnterpriseRecipientResolver::CASE_CONFIRM === $resolution['case']
            && $confirmedId === $resolution['enterprise']?->getId()) {
            return $resolution['enterprise'];
        }

        $name = trim((string) $request->request->get('enterpriseName', ''));

        if ('' === $name) {
            $error = 'schoolMailEnterpriseRequiredError';

            return null;
        }

        $enterprise = (new Enterprise($name))
            ->setCity(trim((string) $request->request->get('enterpriseCity', '')) ?: null)
            ->setCreatedBy($student);

        // The domain is only kept when it really designates a company: on a generic domain,
        // linking will happen on the full address instead.
        if ('' !== $resolution['domain'] && !$resolution['generic']) {
            $enterprise->setEmailDomain($resolution['domain']);
        }

        return $enterprise;
    }

    /**
     * @param array{to: string, subject: string, body: string} $values
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
