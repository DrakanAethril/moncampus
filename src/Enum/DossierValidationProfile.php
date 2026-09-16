<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whether depositing a document ends the matter, or opens a conversation.
 *
 * `Deposit` means the document is **réputé rendu dès le dépôt**: nobody has to act for the cible to
 * be up to date, and the document never appears in a validateur's queue. `Validation` means the
 * deposit is the beginning - a validateur validates it or asks for a correction, and the cible may
 * deposit again (version n+1) even past the date limite when the document allows it.
 *
 * This is a property of the *document*, not of the dossier: a dossier routinely mixes an attestation
 * nobody reads with a rapport two people relire.
 */
enum DossierValidationProfile: string
{
    case Deposit = 'depot';
    case Validation = 'validation';

    public function labelKey(): string
    {
        return match ($this) {
            self::Deposit => 'dossierValidationProfileDepositLabel',
            self::Validation => 'dossierValidationProfileValidationLabel',
        };
    }

    /**
     * The short form the row tag carries — « Validation validateur », where the radio card of the
     * property panel reads « Validation par un validateur ». Two keys rather than one truncation:
     * the card is explaining a choice, the tag is labelling a row that already has four other
     * things on it.
     */
    public function tagKey(): string
    {
        return match ($this) {
            self::Deposit => 'dossierValidationProfileDepositLabel',
            self::Validation => 'dossierValidationProfileValidationTag',
        };
    }

    public function hintKey(): string
    {
        return match ($this) {
            self::Deposit => 'dossierValidationProfileDepositHint',
            self::Validation => 'dossierValidationProfileValidationHint',
        };
    }
}
