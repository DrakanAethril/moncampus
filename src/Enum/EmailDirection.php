<?php

namespace App\Enum;

/**
 * Sens d'un message de la boîte « Courrier école » : reçu depuis l'extérieur via SES inbound,
 * ou émis par la plateforme pour le compte d'un élève via SES SendRawEmail.
 *
 * Les deux sens cohabitent dans App\Entity\EmailMessage plutôt que dans deux tables, parce que
 * le rattachement d'une réponse à son envoi (In-Reply-To → Message-ID) est une jointure de la
 * table sur elle-même.
 */
enum EmailDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
