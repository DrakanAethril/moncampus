<?php

namespace App\Enum;

/**
 * Statut d'acheminement d'un envoi sortant, alimenté par les événements SES consommés depuis la
 * file « events » (App\Command\ConsumeMailEventsCommand).
 *
 * Volontairement sans « ouvert » : le suivi des ouvertures n'est pas activé sur les jeux de
 * configuration SES (pixel de traçage du destinataire, pénalité anti-spam, donnée peu fiable).
 */
enum EmailDeliveryStatus: string
{
    /** Écrit à l'envoi, avant tout retour de SES. */
    case Queued = 'queued';

    /** SES a accepté le message (événement Send) : il ne dit rien de la réception. */
    case Sent = 'sent';

    /** Accepté par le serveur du destinataire (événement Delivery) - le seul statut fiable. */
    case Delivered = 'delivered';

    /** Retardé côté destinataire (boîte pleine, serveur saturé) : ni livré, ni en échec. */
    case Delayed = 'delayed';

    /** Adresse morte ou refus définitif (événement Bounce). */
    case Bounced = 'bounced';

    /** Le destinataire a signalé le message comme indésirable (événement Complaint). */
    case Complained = 'complained';

    /** SES a refusé le message avant émission (événement Reject : virus détecté, par exemple). */
    case Rejected = 'rejected';

    public function labelKey(): string
    {
        return match ($this) {
            self::Queued => 'emailStatusQueuedLabel',
            self::Sent => 'emailStatusSentLabel',
            self::Delivered => 'emailStatusDeliveredLabel',
            self::Delayed => 'emailStatusDelayedLabel',
            self::Bounced => 'emailStatusBouncedLabel',
            self::Complained => 'emailStatusComplainedLabel',
            self::Rejected => 'emailStatusRejectedLabel',
        };
    }

    /** Les états terminaux en échec, ceux qu'on remonte à l'élève pour qu'il corrige le contact. */
    public function isFailure(): bool
    {
        return match ($this) {
            self::Bounced, self::Complained, self::Rejected => true,
            default => false,
        };
    }
}
