<?php

namespace App\Util;

/**
 * Ce qu'une partie locale d'adresse Courrier école a le droit d'être.
 *
 * Rassemblé ici plutôt que dispersé entre le générateur et l'entité parce que les deux doivent
 * répondre exactement pareil : App\Service\StudentMailAddressGenerator ne doit jamais composer une
 * adresse que App\Entity\EmailAlias refuserait, et réciproquement.
 */
final class SchoolMailLocalPart
{
    /**
     * Parties locales qu'aucun élève ne peut recevoir, la réception étant en catch-all : sur ce
     * domaine, une adresse prise l'est pour tout l'établissement.
     *
     * - `dmarc` est déjà servie par notre propre règle de réception SES, qui range les rapports
     *   d'authentification sous un préfixe S3 dédié avant le catch-all.
     * - `postmaster` et `abuse` sont des adresses de service normalisées (RFC 2142) que tout
     *   domaine doit pouvoir honorer, et qu'un correspondant extérieur - ou un fournisseur de
     *   messagerie - peut solliciter à tout moment.
     *
     * @var list<string>
     */
    public const array RESERVED = ['dmarc', 'postmaster', 'abuse'];

    public static function isReserved(string $localPart): bool
    {
        return \in_array(mb_strtolower(trim($localPart)), self::RESERVED, true);
    }

    /**
     * Exige la forme `quelquechose.quelquechose`, imposée aux alias saisis à la main.
     *
     * L'objectif est de rendre impossible la création d'adresses qui se feraient passer pour un
     * service de l'établissement - `comptabilite@`, `direction@`, `scolarite@`. Vues par une
     * entreprise, elles seraient indiscernables d'une adresse officielle, alors qu'elles pointent
     * vers la boîte d'un élève. Le point force une forme qui se lit comme une personne ou comme un
     * périmètre explicite (`stages.sio2`), jamais comme une institution.
     *
     * Interdit aussi le point en bordure et les points consécutifs, que certains serveurs de
     * messagerie rejettent (RFC 5321 : un point ne peut être ni premier, ni dernier, ni doublé
     * hors guillemets).
     */
    public static function hasRequiredDot(string $localPart): bool
    {
        return 1 === preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/', $localPart);
    }

    /** Le jeu de caractères admis, commun à toutes les origines. */
    public static function isWellFormed(string $localPart): bool
    {
        if ('' === $localPart || \strlen($localPart) > 64) {
            return false;
        }

        return 1 === preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*$/', $localPart);
    }
}
