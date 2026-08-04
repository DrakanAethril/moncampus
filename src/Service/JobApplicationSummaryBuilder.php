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
     * @return array{
     *     sentAt: ?\DateTimeImmutable,
     *     replyAt: ?\DateTimeImmutable,
     *     delivered: bool,
     *     failed: bool,
     *     sentCount: int,
     *     replyCount: int,
     *     labelKey: string
     * }
     */
    public function summarize(JobApplication $application): array
    {
        $sentAt = null;
        $replyAt = null;
        $delivered = false;
        $failed = false;
        $sentCount = 0;
        $replyCount = 0;

        foreach ($application->getEmailMessages() as $message) {
            $date = $message->getMessageDate() ?? $message->getCreatedAt();

            if (EmailDirection::Outbound === $message->getDirection()) {
                ++$sentCount;

                if (null === $sentAt || $date < $sentAt) {
                    $sentAt = $date;
                }

                $delivered = $delivered || EmailDeliveryStatus::Delivered === $message->getDeliveryStatus();
                $failed = $failed || $this->hasFailed($message);

                continue;
            }

            ++$replyCount;

            // La réponse retenue est la plus récente : c'est le dernier signe de vie qui intéresse
            // l'élève, pas le premier.
            if (null === $replyAt || $date > $replyAt) {
                $replyAt = $date;
            }
        }

        return [
            'sentAt' => $sentAt,
            'replyAt' => $replyAt,
            'delivered' => $delivered,
            'failed' => $failed,
            'sentCount' => $sentCount,
            'replyCount' => $replyCount,
            'labelKey' => $this->labelKey($replyAt, $failed, $delivered, $sentAt),
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
