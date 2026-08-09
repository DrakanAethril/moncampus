<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailAttachment;
use App\Entity\EmailMessage;
use App\Enum\EmailDirection;
use App\Enum\EmailScanVerdict;
use App\Repository\EmailAliasRepository;
use App\Repository\EmailMessageRepository;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Processes a `.eml` dropped by SES under `incoming/`: MIME parsing, resolution of the recipient
 * student, filing under `applications/{login}/mails/`, database write.
 *
 * Two invariants govern this service:
 *
 * 1. **S3 is authoritative.** The original object under `incoming/` is never deleted here - the
 *    bucket's lifecycle rule (30 days) takes care of that, long after filing succeeded. A bug in
 *    this service therefore cannot destroy a mail.
 * 2. **Idempotent.** SQS delivers at least once. The check runs on the original S3 key then on the
 *    Message-ID, and it happens *before* any expensive work.
 *
 * Attachments are extracted and stored beside the mail, addressed by SHA-256 digest so the same
 * company brochure received by twenty students is written once (§7 of the infra handoff). ClamAV is
 * not deployed, an owned decision: the SES virus verdict travels in the headers and is stored per
 * message, and App\Entity\EmailAttachment already has the column its own verdict will use.
 *
 * A message whose student could not be resolved is kept with `student` at NULL - that is the "to be
 * linked" queue of screen 5a, nothing is lost.
 */
class InboundMailProcessor
{
    public function __construct(
        private readonly S3Client $mailS3Client,
        private readonly EmailAliasRepository $aliasRepository,
        private readonly EmailMessageRepository $messageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $mailBucket,
        private readonly string $studentMailDomain,
    ) {
    }

    /**
     * @return bool true when the message was processed or already had been (the SQS message may be
     *              deleted); any error bubbles up as an exception so the message stays in the queue
     */
    public function process(string $sourceKey): bool
    {
        if (null !== $this->messageRepository->findOneBySourceKey($sourceKey)) {
            $this->logger->info('School mail: object already processed, skipped.', ['key' => $sourceKey]);

            return true;
        }

        $raw = $this->download($sourceKey);
        $parsed = (new MailMimeParser())->parse($raw, true);

        $messageId = $this->normalizeMessageId($parsed->getHeaderValue('Message-ID'));

        if (null !== $messageId && null !== $this->messageRepository->findOneByMessageId($messageId)) {
            $this->logger->info('School mail: Message-ID already stored, skipped.', [
                'key' => $sourceKey,
                'messageId' => $messageId,
            ]);

            return true;
        }

        $message = $this->buildMessage($parsed, $sourceKey, $messageId);

        // Filing comes before the database write: if the S3 copy fails, nothing is stored and the
        // message will come back through the queue. The other way round would leave a row pointing
        // at a key that does not exist.
        $message->setS3Key($this->file($sourceKey, $message));
        $this->attachFiles($parsed, $message);
        $this->linkToApplication($message);

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->logger->info('School mail: inbound message stored.', [
            'key' => $sourceKey,
            'messageId' => $messageId,
            'student' => $message->getStudent()?->getUsername(),
        ]);

        return true;
    }

    /**
     * Stores the mail's attachments beside it and records them.
     *
     * Content-addressed by SHA-256: two students receiving the same brochure write it once on S3
     * while each keeps their own row, and replaying the same mail overwrites the same key rather
     * than piling up copies.
     *
     * An attachment that cannot be stored does not fail the whole message: the mail itself is what
     * matters, its `.eml` holds every attachment anyway, and the reconciliation job can replay it.
     */
    private function attachFiles(IMessage $parsed, EmailMessage $message): void
    {
        $login = $message->getStudent()?->getUsername() ?? 'unattributed';

        foreach ($parsed->getAllAttachmentParts() as $part) {
            // The *binary* stream, not getContent(): the latter runs a charset conversion, which
            // turns a PDF into a corrupted PDF.
            $stream = $part->getBinaryContentStream();
            $content = null !== $stream ? (string) $stream : '';

            if ('' === $content) {
                continue;
            }

            $filename = $part->getFilename() ?? 'piece-jointe';
            $hash = hash('sha256', $content);
            // The prefix the infra handoff names (§7), and the one outbound attachments already use:
            // one place per student for every file that travelled, whichever direction it went.
            $key = sprintf('candidatures/%s/pieces/%s-%s', $login, substr($hash, 0, 16), $this->safeFilename($filename));

            try {
                $this->mailS3Client->putObject([
                    'Bucket' => $this->mailBucket,
                    'Key' => $key,
                    'Body' => $content,
                    'ContentType' => $part->getContentType() ?: 'application/octet-stream',
                ]);
            } catch (\Throwable $exception) {
                $this->logger->error('School mail: could not store an inbound attachment.', [
                    'key' => $key,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            $message->addAttachment(
                (new EmailAttachment())
                    ->setFilename($filename)
                    ->setS3Key($key)
                    ->setContentHash($hash)
                    ->setSizeBytes(\strlen($content))
                    ->setContentType($part->getContentType() ?: null)
                    // No ClamAV yet: what we know is what SES said about the whole message.
                    ->setScanVerdict($message->getVirusVerdict())
            );
        }
    }

    /**
     * A reply inherits the application of the mail it answers, through In-Reply-To (principle #5 of
     * the screens handoff): no question is ever asked of the student.
     *
     * Only that header is followed, deliberately. The infra handoff mentions a sender+recipient+time
     * window fallback, but guessing an application from a coincidence would file a mail under a
     * company it may have nothing to do with - and screen 5a exists precisely so that "we do not
     * know" is an answer the platform can give.
     */
    private function linkToApplication(EmailMessage $message): void
    {
        $inReplyTo = $message->getInReplyTo();

        if (null === $inReplyTo || null === $message->getStudent()) {
            return;
        }

        $answered = $this->messageRepository->findOneByAnyMessageId($inReplyTo);

        if (null === $answered || $answered->getStudent()?->getId() !== $message->getStudent()->getId()) {
            return;
        }

        $message->setJobApplication($answered->getJobApplication());
    }

    /** S3 keys tolerate far less than a filename does; the display name stays untouched in database. */
    private function safeFilename(string $filename): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', false !== $ascii ? $ascii : $filename) ?? 'piece-jointe';

        return trim($clean, '-.') ?: 'piece-jointe';
    }

    private function download(string $key): string
    {
        $result = $this->mailS3Client->getObject([
            'Bucket' => $this->mailBucket,
            'Key' => $key,
        ]);

        return (string) $result['Body'];
    }

    private function buildMessage(IMessage $parsed, string $sourceKey, ?string $messageId): EmailMessage
    {
        $from = $this->firstAddress($parsed, 'From');
        $toAddresses = $this->addresses($parsed, 'To');
        $ccAddresses = $this->addresses($parsed, 'Cc');

        $message = (new EmailMessage())
            ->setDirection(EmailDirection::Inbound)
            ->setMessageId($messageId)
            ->setSourceKey($sourceKey)
            ->setFromAddress($from['email'] ?? 'unknown@invalid')
            ->setFromName($from['name'] ?? null)
            ->setToAddresses($toAddresses)
            ->setCcAddresses($ccAddresses)
            ->setSubject($parsed->getSubject())
            ->setTextBody($parsed->getTextContent())
            ->setHtmlBody($parsed->getHtmlContent())
            ->setInReplyTo($this->normalizeMessageId($parsed->getHeaderValue('In-Reply-To')))
            ->setReferencesHeader($parsed->getHeaderValue('References'))
            ->setSpamVerdict(EmailScanVerdict::fromHeader($parsed->getHeaderValue('X-SES-Spam-Verdict')))
            ->setVirusVerdict(EmailScanVerdict::fromHeader($parsed->getHeaderValue('X-SES-Virus-Verdict')))
            ->setMessageDate($this->messageDate($parsed));

        $localPart = $this->resolveRecipientLocalPart(array_merge($toAddresses, $ccAddresses));
        $message->setRecipientLocalPart($localPart);

        if (null !== $localPart) {
            $message->setStudent($this->aliasRepository->findOneByLocalPart($localPart)?->getUser());
        }

        if (null === $message->getStudent()) {
            $this->logger->notice('School mail: recipient not resolved, left waiting to be linked.', [
                'key' => $sourceKey,
                'localPart' => $localPart,
            ]);
        }

        return $message;
    }

    /**
     * Copies the raw mail under `applications/{login}/mails/`, or under `unattributed/` when the
     * student could not be resolved. The copy happens S3-side (CopyObject); the content never goes
     * back through PHP.
     */
    private function file(string $sourceKey, EmailMessage $message): string
    {
        $owner = $message->getStudent()?->getUsername();
        $name = $this->objectName($message->getMessageId(), $sourceKey);

        $destination = null !== $owner
            ? sprintf('applications/%s/mails/%s.eml', $owner, $name)
            : sprintf('unattributed/mails/%s.eml', $name);

        $this->mailS3Client->copyObject([
            'Bucket' => $this->mailBucket,
            'Key' => $destination,
            'CopySource' => rawurlencode($this->mailBucket.'/'.$sourceKey),
        ]);

        return $destination;
    }

    /**
     * A Message-ID contains characters that are perfectly legal in mail but painful in an S3 key
     * (`/`, spaces, `%`). It is therefore reduced to a digest, keeping the original key as a
     * fallback when the header is missing.
     */
    private function objectName(?string $messageId, string $sourceKey): string
    {
        $seed = $messageId ?? $sourceKey;

        return preg_replace('/[^A-Za-z0-9._-]/', '_', trim($seed, '<>')) ?? hash('sha256', $seed);
    }

    /** The first address on the student domain found among the recipients. */
    private function resolveRecipientLocalPart(array $addresses): ?string
    {
        $suffix = '@'.mb_strtolower($this->studentMailDomain);

        foreach ($addresses as $address) {
            $lowered = mb_strtolower($address);

            if (str_ends_with($lowered, $suffix)) {
                return substr($lowered, 0, -\strlen($suffix));
            }
        }

        return null;
    }

    /** @return list<string> */
    private function addresses(IMessage $parsed, string $header): array
    {
        $found = $parsed->getHeader($header);

        if (!$found instanceof AddressHeader) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($address): string => $address->getEmail(),
            $found->getAddresses(),
        )));
    }

    /** @return array{email?: string, name?: string} */
    private function firstAddress(IMessage $parsed, string $header): array
    {
        $found = $parsed->getHeader($header);

        if (!$found instanceof AddressHeader) {
            return [];
        }

        $first = $found->getAddresses()[0] ?? null;

        if (null === $first) {
            return [];
        }

        $name = $first->getName();

        return ['email' => $first->getEmail(), 'name' => '' !== $name ? $name : null];
    }

    private function messageDate(IMessage $parsed): ?\DateTimeImmutable
    {
        $header = $parsed->getHeader('Date');

        return $header instanceof DateHeader ? $header->getDateTimeImmutable() : null;
    }

    /**
     * The canonical form is the bracketed one, `<id@domain>`, and everything stored goes through
     * here so that both directions agree.
     *
     * The brackets have to be *added back*: the MIME parser hands over ID headers already stripped
     * of them, while App\Service\SchoolMailSender stores what it wrote into the header, brackets
     * included. Without this, a reply's In-Reply-To would never match the send it answers - and that
     * match is the whole mechanism replies are linked by (principle #5 of the screens handoff).
     */
    private function normalizeMessageId(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        if ('' === $trimmed) {
            return null;
        }

        // A References/In-Reply-To header can hold several: only the first one matters here.
        if (preg_match('/<[^>]+>/', $trimmed, $matches)) {
            $trimmed = $matches[0];
        }

        if (!str_starts_with($trimmed, '<')) {
            $trimmed = '<'.$trimmed.'>';
        }

        return mb_substr($trimmed, 0, 255);
    }
}
