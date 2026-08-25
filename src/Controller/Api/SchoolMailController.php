<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Attribute\RequiresFeature;
use App\Entity\EmailAttachment;
use App\Entity\EmailMessage;
use App\Entity\User;
use App\Enum\EmailDirection;
use App\Enum\Feature;
use App\Repository\EmailMessageRepository;
use App\Repository\JobSearchRepository;
use App\Service\JobApplicationResolver;
use App\Service\PostValue;
use App\Service\SchoolMailLockChecker;
use App\Service\SchoolMailSender;
use App\Service\StudentMailboxResolver;
use App\Service\StudentSignatureBuilder;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Courrier école on mobile (design_handoff_mobile, screens 5a-5d) - the phone counterpart of
 * SchoolMailController and SchoolMailComposeController.
 *
 * Same two states as the web: as long as the practice application has not been validated the box
 * is locked, and the mobile screen then shows a centred message with no action at all (5a,
 * principe 5) - which is why the locked case is answered by the very same route, with an empty
 * message list, rather than by a 403 the app would have to interpret.
 *
 * Deliberately smaller than the web mailbox: no drafts, no trash, no search, no folder for the
 * démarches - the phone reads Reçus/Envoyés, opens a mail and answers it.
 */
#[IsGranted('ROLE_STUDENT')]
#[RequiresFeature(Feature::SchoolMail)]
class SchoolMailController extends AbstractController
{
    /** How many mails a folder page holds - the phone list is scrolled, not paginated. */
    private const int PAGE_SIZE = 50;

    public function __construct(
        private readonly EmailMessageRepository $messageRepository,
        private readonly SchoolMailLockChecker $lockChecker,
        private readonly StudentMailboxResolver $mailboxResolver,
        private readonly EntityManagerInterface $entityManager,
        // Wired in config/services.yaml, like SchoolMailAttachmentController's - the mail bucket
        // lives in its own AWS account, not the one the uploads client points at.
        private readonly S3Client $mailS3Client,
        private readonly string $mailBucket,
    ) {
    }

    /** The mailbox itself (5a locked / 5b active). */
    #[Route(path: '/api/school-mail', name: 'api_school_mail', methods: ['GET'])]
    public function folder(Request $request, JobSearchRepository $searchRepository): JsonResponse
    {
        $student = $this->currentUser();
        $locked = $this->lockChecker->isLocked($student);

        if ($locked) {
            return $this->json([
                'locked' => true,
                'address' => null,
                'unread' => 0,
                'canSend' => false,
                'messages' => [],
            ]);
        }

        $direction = 'sent' === $request->query->get('folder')
            ? EmailDirection::Outbound
            : EmailDirection::Inbound;

        $messages = \array_slice(
            $this->messageRepository->findFolderForStudent($student, $direction),
            0,
            self::PAGE_SIZE,
        );

        return $this->json([
            'locked' => false,
            'address' => $this->mailboxResolver->addressFor($student),
            'unread' => $this->messageRepository->countUnreadForStudent($student),
            // A closed job search leaves the mailbox readable but turns sending off, same rule as
            // the web (screen 1a) - the phone hides its FAB rather than failing on send.
            'canSend' => !$searchRepository->isClosedFor($student),
            'messages' => array_map(fn (EmailMessage $message): array => $this->formatRow($message), $messages),
        ]);
    }

    /** Reading a mail (5c). Opening it is what marks it read, as on the web. */
    #[Route(path: '/api/school-mail/{id}', name: 'api_school_mail_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(EmailMessage $message): JsonResponse
    {
        $this->denyUnlessOwned($message);

        if ($message->isUnread()) {
            $message->setReadAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        return $this->json($this->formatRow($message) + [
            'body' => $message->getTextBody() ?? strip_tags((string) $message->getHtmlBody()),
            'to' => $message->getToAddresses(),
            'attachments' => array_map(static fn (EmailAttachment $attachment): array => [
                'id' => $attachment->getId(),
                'filename' => $attachment->getFilename(),
                'sizeBytes' => $attachment->getSizeBytes(),
            ], $message->getAttachments()->toArray()),
        ]);
    }

    /**
     * What the compose screen needs before the student writes (5d): the address they write from,
     * the signature the mail will carry - appended by SchoolMailSender at send time exactly as on
     * the web, so the phone only ever displays it, greyed out - and the démarches already opened,
     * since every school mail belongs to one (principe 6).
     */
    #[Route(path: '/api/school-mail/meta/compose', name: 'api_school_mail_compose_meta', methods: ['GET'])]
    public function composeMeta(StudentSignatureBuilder $signatureBuilder, JobApplicationResolver $resolver): JsonResponse
    {
        $student = $this->currentUser();
        $mailbox = $this->mailboxResolver->addressFor($student);

        return $this->json([
            'address' => $mailbox,
            'signature' => $signatureBuilder->toText($signatureBuilder->build($student, $mailbox)),
            'applications' => $resolver->namesFor($student),
        ]);
    }

    /**
     * Sending (5d). Multipart, since attachments are free-form files picked on the phone; the
     * démarche a mail belongs to is carried by name, like the web form's field.
     */
    #[Route(path: '/api/school-mail/send', name: 'api_school_mail_send', methods: ['POST'])]
    public function send(
        Request $request,
        SchoolMailSender $sender,
        JobApplicationResolver $resolver,
        JobSearchRepository $searchRepository,
    ): JsonResponse {
        $student = $this->currentUser();

        if ($this->lockChecker->isLocked($student) || $searchRepository->isClosedFor($student)) {
            return $this->json(['error' => 'mailbox_locked'], Response::HTTP_FORBIDDEN);
        }

        $to = trim((string) $request->request->get('to'));
        $subject = trim((string) $request->request->get('subject'));
        $body = (string) $request->request->get('body');
        $mailbox = $this->mailboxResolver->addressFor($student);

        if (null === $mailbox) {
            return $this->json(['error' => 'no_mailbox'], Response::HTTP_FORBIDDEN);
        }
        if (!filter_var($to, \FILTER_VALIDATE_EMAIL) || '' === $subject) {
            return $this->json(['error' => 'invalid_input'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $replyTo = null;
        $replyToId = PostValue::int($request, 'replyTo');
        if (0 !== $replyToId) {
            $replyTo = $this->messageRepository->find($replyToId);
            if (null !== $replyTo) {
                $this->denyUnlessOwned($replyTo);
            }
        }

        try {
            $message = $sender->send(
                $student,
                $resolver->applicationFor($student, trim((string) $request->request->get('application'))),
                $mailbox,
                $to,
                $subject,
                $body,
                array_filter($request->files->all()['attachments'] ?? []),
                $replyTo,
            );
        } catch (TransportExceptionInterface) {
            // SES refused the mail; nothing was written, since the row is only created once the
            // mail is out. The app keeps the text the student typed.
            return $this->json(['error' => 'transport_error'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['id' => $message->getId()]);
    }

    /**
     * An attachment's bytes. The web serves these from a session-authenticated route the app
     * cannot reach, so the mobile client downloads them here with its Bearer token and hands the
     * file to the OS itself.
     */
    #[Route(path: '/api/school-mail/attachments/{id}', name: 'api_school_mail_attachment', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function attachment(EmailAttachment $attachment): Response
    {
        $message = $attachment->getEmailMessage();

        if (null === $message) {
            throw $this->createNotFoundException();
        }

        $this->denyUnlessOwned($message);

        try {
            $object = $this->mailS3Client->getObject(['Bucket' => $this->mailBucket, 'Key' => $attachment->getS3Key()]);
        } catch (\Throwable) {
            // The row exists but the object does not: a 404 says the truth better than a 500 would.
            throw $this->createNotFoundException();
        }

        /** @var array{Body: \Psr\Http\Message\StreamInterface} $object */
        $response = new StreamedResponse(static function () use ($object): void {
            echo (string) $object['Body'];
        });
        $response->headers->set('Content-Type', $attachment->getContentType() ?? 'application/octet-stream');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $attachment->getFilename()));

        return $response;
    }

    /**
     * One row of the list (5b): who wrote, when, the subject, a one-line preview, whether it
     * carries attachments and which démarche it belongs to.
     *
     * @return array<string, mixed>
     */
    private function formatRow(EmailMessage $message): array
    {
        $outbound = EmailDirection::Outbound === $message->getDirection();
        $counterpart = $outbound
            ? ($message->getToAddresses()[0] ?? '')
            : $message->getFromAddress();
        $name = $outbound
            ? $counterpart
            : ($message->getFromName() ?? $message->getFromAddress());

        return [
            'id' => $message->getId(),
            'direction' => $message->getDirection()->value,
            'name' => $name,
            'address' => $counterpart,
            'initials' => $this->initialsOf($name),
            'subject' => $message->getSubject(),
            'preview' => $this->previewOf($message),
            'date' => ($message->getMessageDate() ?? $message->getCreatedAt())->format(\DateTimeInterface::ATOM),
            'unread' => $message->isUnread(),
            'hasAttachments' => !$message->getAttachments()->isEmpty(),
            'application' => $message->getJobApplication()?->getName(),
        ];
    }

    /** The one-line preview under the subject - plain text, whitespace collapsed. */
    private function previewOf(EmailMessage $message): string
    {
        $text = $message->getTextBody() ?? strip_tags((string) $message->getHtmlBody());
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, 160);
    }

    /** The two letters of the coloured avatar - a company name, or the address when there is none. */
    private function initialsOf(string $name): string
    {
        $words = preg_split('/[\s@._-]+/u', trim($name), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = array_map(static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), \array_slice($words, 0, 2));

        return '' === implode('', $letters) ? '?' : implode('', $letters);
    }

    private function denyUnlessOwned(EmailMessage $message): void
    {
        if ($message->getStudent()?->getId() !== $this->currentUser()->getId()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
