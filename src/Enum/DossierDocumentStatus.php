<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where one cible stands on one document — **computed, never stored**.
 *
 * Six of the seven cases change without anybody touching a row: `Upcoming` becomes `Missing` on the
 * document's visibility date, `Missing` becomes `Late` on its date limite. Storing them would mean
 * a nightly job whose only purpose is to make the database agree with the calendar, and a screen
 * that is wrong until it runs. App\Service\Dossier\DossierStatusResolver reads the dates and the
 * dépôts instead, at display time.
 *
 * The order of the cases is the order the legend draws them, which is not the order of the
 * lifecycle: the reader looks for « qui est en retard », so the reassuring end comes first.
 */
enum DossierDocumentStatus: string
{
    case Validated = 'valide';
    case AwaitingReview = 'attente';
    case ToCorrect = 'corriger';
    case Deposited = 'depose';
    case Missing = 'manquant';
    case Late = 'retard';
    case Upcoming = 'avenir';

    public function labelKey(): string
    {
        return match ($this) {
            self::Validated => 'dossierStatusValidatedLabel',
            self::AwaitingReview => 'dossierStatusAwaitingReviewLabel',
            self::ToCorrect => 'dossierStatusToCorrectLabel',
            self::Deposited => 'dossierStatusDepositedLabel',
            self::Missing => 'dossierStatusMissingLabel',
            self::Late => 'dossierStatusLateLabel',
            self::Upcoming => 'dossierStatusUpcomingLabel',
        };
    }

    /**
     * The character the pastille carries.
     *
     * Kept as text rather than swapped for the app's icon set: at 26 px these read as one glyph each
     * and the grid puts six hundred of them on a screen, where six hundred inline SVGs would not.
     */
    public function glyph(): string
    {
        return match ($this) {
            self::Validated => '✓',
            self::AwaitingReview => '⟳',
            self::ToCorrect => '!',
            self::Deposited => '↑',
            self::Missing => '·',
            self::Late => '⏱',
            self::Upcoming => '–',
        };
    }

    /** The `--dd-*` colour pair, used as a `cm-dd-*--{modifier}` suffix on tags and pastilles. */
    public function modifier(): string
    {
        return $this->value;
    }

    /**
     * Is the cible's side of this document done?
     *
     * The asymmetry is the whole point of the two validation profiles: a `Deposited` document is
     * finished because nobody has to read it, and an `AwaitingReview` one is not, although the cible
     * has done exactly the same thing. The avancement bar counts this, over the *obligatoires* only.
     */
    public function isSettled(): bool
    {
        return self::Validated === $this || self::Deposited === $this;
    }

    /** A dépôt exists and is sitting in a validateur's queue. */
    public function isAwaitingValidator(): bool
    {
        return self::AwaitingReview === $this;
    }

    /** Nothing has been handed in, and the date limite has gone past. */
    public function isLate(): bool
    {
        return self::Late === $this;
    }

    /**
     * The statuses the « Par document » filter bar offers, in its own order.
     *
     * `Upcoming` is absent deliberately: filtering a document on « à venir » answers either
     * everybody or nobody, since the visibility date is the document's and not the cible's.
     *
     * @return list<self>
     */
    public static function filterable(): array
    {
        return [self::Validated, self::AwaitingReview, self::ToCorrect, self::Deposited, self::Missing, self::Late];
    }

    /**
     * The legend of the Suivi screen — the six statuses a cell can hold once a document is visible.
     *
     * @return list<self>
     */
    public static function legend(): array
    {
        return self::filterable();
    }
}
