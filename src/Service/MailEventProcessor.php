<?php

namespace App\Service;

use App\Entity\SuppressedEmailAddress;
use App\Enum\EmailDeliveryStatus;
use App\Enum\EmailSuppressionReason;
use App\Repository\EmailMessageRepository;
use App\Repository\SuppressedEmailAddressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns one SES event into the delivery status a screen can show
 * (design_handoff_courrier_ecole_infra §6).
 *
 * Correlation is by Message-ID, the one we set ourselves at send time - which is why
 * App\Service\SchoolMailSender insists on setting it rather than letting the transport invent one.
 *
 * **No open tracking.** SES publishes `Open` events and the infra handoff lists them, but the
 * screens handoff forbids them outright (principle #1): an open pixel says more about the
 * recipient's mail client than about them, and the platform must not pretend otherwise. `Open`
 * events are acknowledged and dropped.
 *
 * Statuses only ever move forward: a `Send` arriving after a `Delivery` - SQS gives no ordering
 * guarantee - must not un-deliver a mail that was delivered.
 */
class MailEventProcessor
{
    /** How far along the delivery story each status is. A later event never loses to an earlier one. */
    private const array RANK = [
        'queued' => 0,
        'sent' => 1,
        'delayed' => 2,
        'delivered' => 3,
        'complained' => 4,
        'bounced' => 5,
        'rejected' => 5,
    ];

    public function __construct(
        private readonly EmailMessageRepository $messageRepository,
        private readonly SuppressedEmailAddressRepository $suppressionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool true when the event was handled or is of no interest (the SQS message may be
     *              deleted); false when the mail it talks about is unknown, which is worth keeping
     */
    public function process(string $payload): bool
    {
        $event = $this->decodeEvent($payload);

        if (null === $event) {
            return true;
        }

        $type = (string) ($event['eventType'] ?? $event['notificationType'] ?? '');
        $messageId = $this->normalizeMessageId($event['mail']['commonHeaders']['messageId'] ?? null);

        if (null === $messageId) {
            $this->logger->notice('School mail: SES event without a usable Message-ID, dropped.', ['type' => $type]);

            return true;
        }

        $message = $this->messageRepository->findOneByMessageId($messageId);

        if (null === $message) {
            // The send may simply not be written yet - the event queue can outrun our own commit.
            $this->logger->notice('School mail: SES event about an unknown mail, left in the queue.', [
                'type' => $type,
                'messageId' => $messageId,
            ]);

            return false;
        }

        $status = match ($type) {
            'Send' => EmailDeliveryStatus::Sent,
            'Delivery' => EmailDeliveryStatus::Delivered,
            'DeliveryDelay' => EmailDeliveryStatus::Delayed,
            'Bounce' => EmailDeliveryStatus::Bounced,
            'Complaint' => EmailDeliveryStatus::Complained,
            'Reject' => EmailDeliveryStatus::Rejected,
            default => null,
        };

        if (null === $status) {
            return true;
        }

        if ($this->outranksCurrent($status, $message->getDeliveryStatus())) {
            $message->setDeliveryStatus($status);
        }

        $this->suppressIfNeeded($type, $event);
        $this->entityManager->flush();

        $this->logger->info('School mail: delivery status updated from an SES event.', [
            'type' => $type,
            'messageId' => $messageId,
            'status' => $message->getDeliveryStatus()?->value,
        ]);

        return true;
    }

    /**
     * SES publishes through SNS, and SNS wraps the real event in a `Message` string. Both shapes are
     * accepted so that a queue subscribed straight to SES works too.
     *
     * @return ?array<string, mixed>
     */
    private function decodeEvent(string $payload): ?array
    {
        $decoded = json_decode($payload, true);

        if (!\is_array($decoded)) {
            return null;
        }

        if (isset($decoded['Message']) && \is_string($decoded['Message'])) {
            $inner = json_decode($decoded['Message'], true);

            return \is_array($inner) ? $inner : null;
        }

        return $decoded;
    }

    /** @param array<string, mixed> $event */
    private function suppressIfNeeded(string $type, array $event): void
    {
        [$reason, $recipients, $detail] = match ($type) {
            // Only permanent bounces: a full mailbox is a bad day, not a dead address, and
            // suppressing it would cost a student a real contact.
            'Bounce' => 'Permanent' === ($event['bounce']['bounceType'] ?? null)
                ? [
                    EmailSuppressionReason::HardBounce,
                    array_column($event['bounce']['bouncedRecipients'] ?? [], 'emailAddress'),
                    $event['bounce']['bounceSubType'] ?? null,
                ]
                : [null, [], null],
            'Complaint' => [
                EmailSuppressionReason::Complaint,
                array_column($event['complaint']['complainedRecipients'] ?? [], 'emailAddress'),
                $event['complaint']['complaintFeedbackType'] ?? null,
            ],
            default => [null, [], null],
        };

        if (null === $reason) {
            return;
        }

        foreach ($recipients as $address) {
            if ('' === trim((string) $address) || $this->suppressionRepository->isSuppressed((string) $address)) {
                continue;
            }

            $this->entityManager->persist(new SuppressedEmailAddress((string) $address, $reason, $detail));

            $this->logger->warning('School mail: address added to the suppression list.', [
                'address' => $address,
                'reason' => $reason->value,
                'detail' => $detail,
            ]);
        }
    }

    private function outranksCurrent(EmailDeliveryStatus $candidate, ?EmailDeliveryStatus $current): bool
    {
        if (null === $current) {
            return true;
        }

        return (self::RANK[$candidate->value] ?? 0) >= (self::RANK[$current->value] ?? 0);
    }

    /** Angle brackets are the canonical form, and what we stored at send time. */
    private function normalizeMessageId(?string $messageId): ?string
    {
        $messageId = trim((string) $messageId);

        if ('' === $messageId) {
            return null;
        }

        return str_starts_with($messageId, '<') ? $messageId : '<'.$messageId.'>';
    }
}
