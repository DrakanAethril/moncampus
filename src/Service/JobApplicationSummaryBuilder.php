<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailMessage;
use App\Entity\JobApplication;
use App\Enum\EmailDeliveryStatus;
use App\Enum\EmailDirection;

/**
 * Summarises an application for display: "Sent on 31/08 - delivered, no reply", "Reply received on
 * 15/09" (design_handoff_stage_alternance, screens 2a and 2b).
 *
 * This summary is **computed, never stored**, and that is the point. The handoff's principle #1
 * forbids any analysis of replies: the platform gathers mails, it does not sort them. A "status"
 * column in the database would have invited someone, at the first business need, to write
 * "rejected" or "interview" into it. Here there is nothing to write - the summary is derived at
 * every render from verifiable facts: a send date, an SES delivery event, the existence of an
 * inbound message.
 */
class JobApplicationSummaryBuilder
{
    /**
     * How long a mail may stay unanswered before the screen says so. This is the mockup's follow-up
     * reminder delay ("after the D+10 reminder", screen 2a): below it, the application is simply
     * recent, and the chip settles for dating the last mail.
     */
    private const int NO_REPLY_AFTER_DAYS = 10;

    /**
     * @return array{
     *     sentAt: ?\DateTimeImmutable,
     *     lastSentAt: ?\DateTimeImmutable,
     *     replyAt: ?\DateTimeImmutable,
     *     lastActivityAt: ?\DateTimeImmutable,
     *     delivered: bool,
     *     failed: bool,
     *     sentCount: int,
     *     replyCount: int,
     *     mailCount: int,
     *     replyAttachmentCount: int,
     *     labelKey: string,
     *     chip: ?array{variant: string, labelKey: string, date: ?\DateTimeImmutable}
     * }
     */
    public function summarize(JobApplication $application): array
    {
        $sentAt = null;
        $lastSentAt = null;
        $replyAt = null;
        $lastActivityAt = null;
        $delivered = false;
        $failed = false;
        $sentCount = 0;
        $replyCount = 0;
        $replyAttachmentCount = 0;

        foreach ($application->getEmailMessages() as $message) {
            $date = $message->getMessageDate() ?? $message->getCreatedAt();

            if (null === $lastActivityAt || $date > $lastActivityAt) {
                $lastActivityAt = $date;
            }

            if (EmailDirection::Outbound === $message->getDirection()) {
                ++$sentCount;

                if (null === $sentAt || $date < $sentAt) {
                    $sentAt = $date;
                }

                if (null === $lastSentAt || $date > $lastSentAt) {
                    $lastSentAt = $date;
                }

                $delivered = $delivered || EmailDeliveryStatus::Delivered === $message->getDeliveryStatus();
                $failed = $failed || $this->hasFailed($message);

                continue;
            }

            ++$replyCount;
            $replyAttachmentCount += $message->getAttachments()->count();

            // The reply kept is the most recent one: it is the latest sign of life that matters to
            // the student, not the first.
            if (null === $replyAt || $date > $replyAt) {
                $replyAt = $date;
            }
        }

        return [
            'sentAt' => $sentAt,
            'lastSentAt' => $lastSentAt,
            'replyAt' => $replyAt,
            'lastActivityAt' => $lastActivityAt,
            'delivered' => $delivered,
            'failed' => $failed,
            'sentCount' => $sentCount,
            'replyCount' => $replyCount,
            'mailCount' => $sentCount + $replyCount,
            'replyAttachmentCount' => $replyAttachmentCount,
            'labelKey' => $this->labelKey($replyAt, $failed, $delivered, $sentAt),
            'chip' => $this->chip($replyAt, $failed, $lastSentAt),
        ];
    }

    private function hasFailed(EmailMessage $message): bool
    {
        return null !== $message->getDeliveryStatus() && $message->getDeliveryStatus()->isFailure();
    }

    /**
     * The priority order is the one that serves the student: a reply beats everything, a delivery
     * failure comes before the delivery receipt, and "nothing sent" closes the march.
     */
    /**
     * The mockup's right-hand chip. All four states are verifiable without reading a single mail: a
     * reply arrived, a send failed, a send has been waiting longer than the follow-up delay, or
     * nothing happened beyond the last mail and its date.
     *
     * @return ?array{variant: string, labelKey: string, date: ?\DateTimeImmutable}
     */
    private function chip(?\DateTimeImmutable $replyAt, bool $failed, ?\DateTimeImmutable $lastSentAt): ?array
    {
        if (null !== $replyAt) {
            return ['variant' => 'reply', 'labelKey' => 'jobApplicationReplyChipLabel', 'date' => null];
        }

        if ($failed) {
            return ['variant' => 'failed', 'labelKey' => 'jobApplicationFailedChipLabel', 'date' => null];
        }

        // An application with no mail sent carries no chip: there is nothing to say about it.
        if (null === $lastSentAt) {
            return null;
        }

        $waitingSince = $lastSentAt->diff(new \DateTimeImmutable())->days;

        if ($waitingSince >= self::NO_REPLY_AFTER_DAYS) {
            return ['variant' => 'waiting', 'labelKey' => 'jobApplicationNoReplyChipLabel', 'date' => null];
        }

        return ['variant' => 'neutral', 'labelKey' => 'jobApplicationLastMailChipLabel', 'date' => $lastSentAt];
    }

    private function labelKey(?\DateTimeImmutable $replyAt, bool $failed, bool $delivered, ?\DateTimeImmutable $sentAt): string
    {
        return match (true) {
            null !== $replyAt => 'jobApplicationSummaryReplyReceived',
            $failed => 'jobApplicationSummaryFailed',
            $delivered => 'jobApplicationSummaryDeliveredNoReply',
            null !== $sentAt => 'jobApplicationSummarySentNoReply',
            default => 'jobApplicationSummaryNoMail',
        };
    }
}
