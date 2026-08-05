<?php

namespace App\Service;

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
 * Deliberately out of scope for this first slice: attachment extraction and linking to
 * applications, which depend on the part-2 handoff still being designed. A message whose student
 * could not be resolved is kept with `student` at NULL - that is the "to be linked" queue, nothing
 * is lost.
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

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->logger->info('School mail: inbound message stored.', [
            'key' => $sourceKey,
            'messageId' => $messageId,
            'student' => $message->getStudent()?->getUsername(),
        ]);

        return true;
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

    /** Angle brackets are kept (that is the canonical form), the rest is normalised. */
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

        return mb_substr($trimmed, 0, 255);
    }
}
