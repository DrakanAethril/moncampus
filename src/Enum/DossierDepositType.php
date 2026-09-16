<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a cible hands in for one document: a file, or a link.
 *
 * Named "deposit type" rather than "document type" because it says nothing about the document -
 * « Lien du dépôt Git » and « Rapport de stage » are both documents, and what differs is the shape
 * of the answer. A document never accepts both: the screen draws one control, and
 * App\Entity\DossierSubmission carries either a storage key or a URL, never the two.
 */
enum DossierDepositType: string
{
    case Upload = 'upload';
    case Url = 'url';

    public function labelKey(): string
    {
        return match ($this) {
            self::Upload => 'dossierDepositTypeUploadLabel',
            self::Url => 'dossierDepositTypeUrlLabel',
        };
    }

    /**
     * The word inside the rules line — « … · dépassement bloqué · fichier ».
     *
     * A key of its own rather than a `|lower` on the label: « Lien URL » lowercased reads « lien
     * url », and the acronym has to keep its capitals.
     */
    public function ruleKey(): string
    {
        return match ($this) {
            self::Upload => 'dossierDepositTypeUploadRule',
            self::Url => 'dossierDepositTypeUrlRule',
        };
    }

    /** The short badge on a document row - FICHIER / URL. */
    public function badgeKey(): string
    {
        return match ($this) {
            self::Upload => 'dossierDepositTypeUploadBadge',
            self::Url => 'dossierDepositTypeUrlBadge',
        };
    }
}
