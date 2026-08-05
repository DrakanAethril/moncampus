<?php

namespace App\Service;

use App\Entity\EmailMessage;
use App\Entity\JobApplication;
use App\Enum\EmailDeliveryStatus;
use App\Enum\EmailDirection;

/**
 * Résume une démarche pour l'affichage : « Envoyée le 31/08 · délivrée, sans réponse »,
 * « Réponse reçue le 15/09 » (design_handoff_stage_alternance, écrans 2a et 2b).
 *
 * Ce résumé est **calculé, jamais stocké**, et c'est le point important. Le principe n°1 du
 * handoff interdit toute analyse des réponses : la plateforme rassemble les mails, elle ne les
 * classe pas. Une colonne « statut » en base aurait invité, au premier besoin métier, à y écrire
 * « refus » ou « entretien ». Ici il n'y a rien à écrire — le résumé se déduit à chaque affichage
 * de faits vérifiables : une date d'envoi, un événement SES de délivrance, l'existence d'un
 * message entrant.
 */
class JobApplicationSummaryBuilder
{
    /**
     * Le délai au bout duquel un envoi resté sans réponse est signalé comme tel. C'est celui du
     * rappel de relance de la créa (« après rappel J+10 », écran 2a) : en deçà, la démarche est
     * simplement récente, et la pastille se contente de dater le dernier mail.
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

            // La réponse retenue est la plus récente : c'est le dernier signe de vie qui intéresse
            // l'élève, pas le premier.
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
     * L'ordre de priorité est celui de l'utilité pour l'élève : une réponse prime sur tout, un
     * échec d'envoi passe avant l'accusé de délivrance, et l'absence d'envoi ferme la marche.
     */
    /**
     * La pastille de droite de la créa. Les quatre états sont vérifiables sans lire un seul mail :
     * une réponse est arrivée, un envoi a échoué, un envoi attend depuis plus que le délai de
     * relance, ou il ne s'est rien passé de plus que le dernier mail et sa date.
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

        // Une démarche sans aucun envoi ne porte pas de pastille : il n'y a rien à en dire.
        if (null === $lastSentAt) {
            return null;
        }

        $waitingSince = $lastSentAt->diff(new \DateTimeImmutable())->days;

        if (null !== $waitingSince && $waitingSince >= self::NO_REPLY_AFTER_DAYS) {
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
