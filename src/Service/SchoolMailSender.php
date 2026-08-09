<?php

namespace App\Service;

use App\Entity\EmailAttachment;
use App\Entity\EmailMessage;
use App\Entity\JobApplication;
use App\Entity\User;
use App\Enum\EmailDeliveryStatus;
use App\Enum\EmailDirection;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends a student's mail to a company (design_handoff_stage_alternance, screen 3d), following the
 * mail layer already specified by design_handoff_courrier_ecole_infra (§5).
 *
 * Two points of that spec drive everything else:
 *
 * - **the Message-ID is the one SES assigns**, not the one we generate. SES rewrites the header on
 *   send - proven by looping a mail through our own catch-all - so what we store is what the
 *   recipient sees, and therefore what their reply will carry in In-Reply-To. That match is how the
 *   inbound worker finds the application again without asking anyone (principle #5);
 * - **the database row is written at send time**, the application already holding everything it
 *   needs, and the `.eml` is archived on S3, which stays the source of truth.
 *
 * A failed S3 archive does not fail the send: the mail is gone, and denying it would be worse than
 * recording it. The failure is logged as an error - the infra's reconciliation job is what catches
 * up, not the student.
 */
class SchoolMailSender
{
    /** The mailer transport declared for school mail in config/packages/mailer.yaml. */
    private const string TRANSPORT = 'school_mail';

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly EntityManagerInterface $entityManager,
        private readonly StudentSignatureBuilder $signatureBuilder,
        private readonly LoggerInterface $logger,
        private readonly S3Client $mailS3Client,
        private readonly string $mailBucket,
        private readonly string $region,
        private readonly ?string $configurationSet = null,
    ) {
    }

    /**
     * @param list<UploadedFile> $uploads
     */
    public function send(
        User $student,
        JobApplication $application,
        string $mailbox,
        string $recipient,
        string $subject,
        string $body,
        array $uploads = [],
        ?EmailMessage $inReplyTo = null,
    ): EmailMessage {
        $signature = $this->signatureBuilder->build($student, $mailbox);
        $messageId = $this->generateMessageId($mailbox);
        $sentAt = new \DateTimeImmutable();

        $textBody = rtrim($body)."\n\n".$this->signatureBuilder->toText($signature);
        $htmlBody = $this->renderHtmlBody($body, $signature);

        $email = (new Email())
            ->from(new Address($mailbox, $this->senderName($student)))
            ->to($recipient)
            ->subject($subject)
            ->text($textBody)
            ->html($htmlBody);

        $headers = $email->getHeaders();
        // addIdHeader expects the bare id: the angle brackets are added by the MIME layer, while
        // they are part of what we store and of what a reply's In-Reply-To will send back.
        $headers->addIdHeader('Message-ID', trim($messageId, '<>'));

        // School mail leaves through its own transport (see config/packages/mailer.yaml): the SES
        // account that owns the student domain, receives the replies and publishes the delivery
        // events. Without this header the mail would take the platform's own transport - Mailpit in
        // dev - and nothing would ever come back about it. Symfony strips the header before sending.
        $headers->addTextHeader('X-Transport', self::TRANSPORT);

        if (null !== $this->configurationSet && '' !== $this->configurationSet) {
            $headers->addTextHeader('X-SES-CONFIGURATION-SET', $this->configurationSet);
        }

        if (null !== $inReplyTo && null !== $inReplyTo->getMessageId()) {
            $headers->addTextHeader('In-Reply-To', $inReplyTo->getMessageId());
            $headers->addTextHeader('References', trim(($inReplyTo->getReferencesHeader() ?? '').' '.$inReplyTo->getMessageId()));
        }

        $attachments = [];

        foreach ($uploads as $upload) {
            $content = file_get_contents($upload->getPathname());

            if (false === $content) {
                continue;
            }

            $filename = $upload->getClientOriginalName();
            $email->attach($content, $filename, $upload->getClientMimeType());
            $attachments[] = ['filename' => $filename, 'content' => $content, 'type' => $upload->getClientMimeType()];
        }

        // The transport rather than the mailer, to get the SentMessage back: it carries the
        // identifier SES assigned, which is the only one events and replies will ever mention.
        $sent = $this->transport->send($email);
        $providerId = $sent?->getMessageId();

        if (null !== $providerId && '' !== $providerId) {
            // What the recipient actually sees, and therefore what their reply will put in
            // In-Reply-To. Reconstructed rather than guessed: SES appends its regional domain to the
            // id it returns, as a loop-back through our own catch-all confirmed.
            $messageId = sprintf('<%s@%s.amazonses.com>', trim($providerId, '<>'), $this->region);
        }

        $login = $student->getUsername();
        $s3Key = sprintf('candidatures/%s/mails/%s.eml', $login, trim($messageId, '<>'));

        $message = (new EmailMessage())
            ->setDirection(EmailDirection::Outbound)
            ->setStudent($student)
            ->setMessageId($messageId)
            ->setProviderMessageId(null !== $providerId ? trim($providerId, '<>') : null)
            ->setFromAddress($mailbox)
            ->setFromName($this->senderName($student))
            ->setToAddresses([$recipient])
            ->setSubject($subject)
            ->setTextBody($textBody)
            ->setHtmlBody($htmlBody)
            ->setS3Key($s3Key)
            ->setMessageDate($sentAt)
            // "Sent", not "delivered": delivery is an SES event that will arrive through the
            // events queue. Nothing here allows us to claim it.
            ->setDeliveryStatus(EmailDeliveryStatus::Sent)
            ->setJobApplication($application);

        if (null !== $inReplyTo) {
            $message->setInReplyTo($inReplyTo->getMessageId());
        }

        foreach ($attachments as $attachment) {
            $hash = hash('sha256', $attachment['content']);
            $key = sprintf('candidatures/%s/pieces/%s-%s', $login, substr($hash, 0, 16), $attachment['filename']);

            $message->addAttachment(
                (new EmailAttachment())
                    ->setFilename($attachment['filename'])
                    ->setS3Key($key)
                    ->setContentHash($hash)
                    ->setSizeBytes(\strlen($attachment['content']))
                    ->setContentType($attachment['type'])
            );

            $this->archive($key, $attachment['content'], $attachment['type']);
        }

        $this->entityManager->persist($message);

        // A démarche named for the first time on the compose screen reaches us unsaved: it is
        // written here rather than there, so that a send SES refuses leaves no empty démarche behind.
        if (null === $application->getId()) {
            $this->entityManager->persist($application);
        }

        $this->entityManager->flush();

        $this->archive($s3Key, $email->toString(), 'message/rfc822');

        return $message;
    }

    private function senderName(User $student): string
    {
        return trim(($student->getFirstname() ?? '').' '.($student->getLastname() ?? '')) ?: $student->getUsername();
    }

    private function generateMessageId(string $mailbox): string
    {
        $domain = substr($mailbox, strrpos($mailbox, '@') + 1);

        return sprintf('<%s@%s>', bin2hex(random_bytes(16)), $domain);
    }

    /** @param array{name: string, formation: ?string, address: ?string, phone: ?string, linkedin: ?string, github: ?string} $signature */
    private function renderHtmlBody(string $body, array $signature): string
    {
        $links = array_filter([$signature['linkedin'] ?? null, $signature['github'] ?? null]);

        $lines = array_filter([
            '<b>'.htmlspecialchars($signature['name'], \ENT_QUOTES).'</b>',
            null !== $signature['formation'] ? htmlspecialchars($signature['formation'], \ENT_QUOTES).' · Institution Beaupeyrat' : null,
            null !== $signature['address'] ? htmlspecialchars($signature['address'], \ENT_QUOTES) : null,
            null !== $signature['phone'] ? htmlspecialchars($signature['phone'], \ENT_QUOTES) : null,
            [] !== $links ? implode(' · ', array_map(static fn (string $link): string => htmlspecialchars($link, \ENT_QUOTES), $links)) : null,
        ]);

        return '<div style="font-family:sans-serif;font-size:14px;color:#3d4f5c;line-height:1.6">'
            .nl2br(htmlspecialchars($body, \ENT_QUOTES))
            .'<div style="margin-top:18px;border-left:2px solid #c9a04e;padding-left:12px;line-height:1.55">'
            .implode('<br>', $lines)
            .'</div></div>';
    }

    private function archive(string $key, string $content, string $contentType): void
    {
        try {
            $this->mailS3Client->putObject([
                'Bucket' => $this->mailBucket,
                'Key' => $key,
                'Body' => $content,
                'ContentType' => $contentType,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('School mail: could not archive an outgoing mail to S3.', [
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
